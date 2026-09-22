<?php

namespace App\Controllers;

use App\Auth;
use App\AuditLog;
use App\Database;
use App\View;
use InvalidArgumentException;
use PDO;

final class TableController
{
    protected PDO $pdo;
    protected AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function listForDatabase(string $db): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidDatabase($db);

        return View::render('tables', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'tables' => $this->listTables($db),
        ]);
    }

    public function structure(string $db, string $table): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        return View::render('table_structure', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
        ]);
    }

    public function data(string $db, string $table): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        $columns = $this->columns($db, $table);
        $validColumns = array_column($columns, 'COLUMN_NAME');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = (int) ($_GET['page_size'] ?? 50);

        $sortColumn = $_GET['sort'] ?? null;
        $sortColumn = is_string($sortColumn) ? $sortColumn : null;

        $sortDir = $_GET['dir'] ?? 'ASC';
        $sortDir = is_string($sortDir) ? $sortDir : 'ASC';

        $rawFilters = $_GET['filter'] ?? [];
        $rawFilters = is_array($rawFilters) ? $rawFilters : [];
        $filters = array_filter(
            array_intersect_key($rawFilters, array_flip($validColumns)),
            fn($v) => is_string($v)
        );

        $result = $this->listRows($db, $table, $page, $pageSize, $sortColumn, $sortDir, $filters);

        return View::render('table_data', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $columns,
            'primaryKey' => $this->primaryKeyColumn($db, $table),
            'result' => $result,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'filters' => $filters,
            'csrfToken' => \App\Csrf::token(),
        ]);
    }

    public function listRows(
        string $db,
        string $table,
        int $page,
        int $pageSize,
        ?string $sortColumn,
        string $sortDir,
        array $filters
    ): array {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');

        $where = [];
        $params = [];
        foreach ($filters as $col => $value) {
            if (!in_array($col, $validColumns, true) || $value === '') {
                continue;
            }
            $where[] = sprintf('`%s` LIKE :filter_%s', $col, $col);
            $params['filter_' . $col] = '%' . $value . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $orderSql = '';
        if ($sortColumn !== null && in_array($sortColumn, $validColumns, true)) {
            $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
            $orderSql = sprintf('ORDER BY `%s` %s', $sortColumn, $dir);
        }

        $pageSize = max(1, min($pageSize, 500));
        $offset = max(0, ($page - 1) * $pageSize);

        $sql = sprintf('SELECT * FROM `%s`.`%s` %s %s LIMIT :limit OFFSET :offset', $db, $table, $whereSql, $orderSql);
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countSql = sprintf('SELECT COUNT(*) FROM `%s`.`%s` %s', $db, $table, $whereSql);
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
    }

    public function listTables(string $db): array
    {
        $this->assertValidDatabase($db);

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $db]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function columns(string $db, string $table): array
    {
        $this->assertValidTable($db, $table);

        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $db, 't' => $table]);

        return $stmt->fetchAll();
    }

    public function primaryKeyColumn(string $db, string $table): ?string
    {
        foreach ($this->columns($db, $table) as $col) {
            if ($col['COLUMN_KEY'] === 'PRI') {
                return $col['COLUMN_NAME'];
            }
        }
        return null;
    }

    protected function assertValidDatabase(string $db): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
        $stmt->execute(['db' => $db]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Unknown database');
        }
    }

    protected function assertValidTable(string $db, string $table): void
    {
        $this->assertValidDatabase($db);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t'
        );
        $stmt->execute(['db' => $db, 't' => $table]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Unknown table');
        }
    }
}
