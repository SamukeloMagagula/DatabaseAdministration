<?php

namespace App\Controllers;

use App\Auth;
use App\AuditLog;
use App\Csrf;
use App\Database;
use App\Http;
use App\Roles;
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

    public function newRowForm(string $db, string $table): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        return View::render('row_form', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
            'row' => null,
            'primaryKey' => $this->primaryKeyColumn($db, $table),
            'pkValue' => null,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function createRow(string $db, string $table): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);

        $user = Auth::currentUser();
        $this->insertRow($db, $table, $_POST['fields'] ?? [], $user['id'], $user['username']);

        return Http::redirect('/db/' . rawurlencode($db) . '/table/' . rawurlencode($table));
    }

    public function editRowForm(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);
        if ($pkColumn === null) {
            http_response_code(400);
            return 'Table has no primary key; editing is not supported.';
        }

        return View::render('row_form', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
            'row' => $this->findRow($db, $table, $pkColumn, $pk),
            'primaryKey' => $pkColumn,
            'pkValue' => $pk,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function updateRow(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);
        if ($pkColumn === null) {
            http_response_code(400);
            return 'Table has no primary key; editing is not supported.';
        }

        $user = Auth::currentUser();
        $this->updateRowData($db, $table, $pkColumn, $pk, $_POST['fields'] ?? [], $user['id'], $user['username']);

        return Http::redirect('/db/' . rawurlencode($db) . '/table/' . rawurlencode($table));
    }

    public function deleteRow(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);
        if ($pkColumn === null) {
            http_response_code(400);
            return 'Table has no primary key; editing is not supported.';
        }

        $user = Auth::currentUser();
        $this->deleteRowData($db, $table, $pkColumn, $pk, $user['id'], $user['username']);

        return Http::redirect('/db/' . rawurlencode($db) . '/table/' . rawurlencode($table));
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

        // Placeholder names are synthetic (filter0, filter1, …) rather than
        // derived from the column name: a legal column such as `order-date`
        // or `first name` is not a valid PDO placeholder token.
        $where = [];
        $params = [];
        $i = 0;
        foreach ($filters as $col => $value) {
            if (!in_array($col, $validColumns, true) || $value === '') {
                continue;
            }
            $ph = 'filter' . $i++;
            $where[] = sprintf('`%s` LIKE :%s', $col, $ph);
            $params[$ph] = '%' . $value . '%';
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

    public function findRow(string $db, string $table, string $pkColumn, $pkValue): ?array
    {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        if (!in_array($pkColumn, $validColumns, true)) {
            throw new InvalidArgumentException('Invalid primary key column');
        }
        $sql = sprintf('SELECT * FROM `%s`.`%s` WHERE `%s` = :pk', $db, $table, $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pk' => $pkValue]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertRow(string $db, string $table, array $data, int $userId, string $username): void
    {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        $data = array_intersect_key($data, array_flip($validColumns));
        if (!$data) {
            throw new InvalidArgumentException('No valid columns supplied');
        }

        // Synthetic placeholder names (v0, v1, …) — see the note in listRows().
        $columns = array_keys($data);
        $placeholders = [];
        $params = [];
        foreach ($columns as $i => $c) {
            $ph = 'v' . $i;
            $placeholders[] = ':' . $ph;
            $params[$ph] = $data[$c];
        }

        $sql = sprintf(
            'INSERT INTO `%s`.`%s` (%s) VALUES (%s)',
            $db,
            $table,
            implode(', ', array_map(fn($c) => "`$c`", $columns)),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->auditLog->record($userId, $username, 'ROW_INSERT', $db, $table, json_encode($data));
    }

    public function updateRowData(
        string $db,
        string $table,
        string $pkColumn,
        $pkValue,
        array $data,
        int $userId,
        string $username
    ): void {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        $data = array_intersect_key($data, array_flip($validColumns));
        unset($data[$pkColumn]);
        if (!$data) {
            throw new InvalidArgumentException('No valid columns supplied');
        }
        if (!in_array($pkColumn, $validColumns, true)) {
            throw new InvalidArgumentException('Invalid primary key column');
        }

        // Synthetic placeholder names (v0, v1, …) — see the note in listRows().
        // This also removes the chance of a column literally named `pk_value`
        // colliding with the WHERE-clause placeholder.
        $setParts = [];
        $params = [];
        $i = 0;
        foreach ($data as $c => $v) {
            $ph = 'v' . $i++;
            $setParts[] = "`$c` = :$ph";
            $params[$ph] = $v;
        }
        $params['pk_value'] = $pkValue;

        $sql = sprintf('UPDATE `%s`.`%s` SET %s WHERE `%s` = :pk_value', $db, $table, implode(', ', $setParts), $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->auditLog->record($userId, $username, 'ROW_UPDATE', $db, $table, json_encode(['pk' => $pkValue, 'set' => $data]));
    }

    public function deleteRowData(string $db, string $table, string $pkColumn, $pkValue, int $userId, string $username): void
    {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        if (!in_array($pkColumn, $validColumns, true)) {
            throw new InvalidArgumentException('Invalid primary key column');
        }

        $sql = sprintf('DELETE FROM `%s`.`%s` WHERE `%s` = :pk_value', $db, $table, $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pk_value' => $pkValue]);

        $this->auditLog->record($userId, $username, 'ROW_DELETE', $db, $table, json_encode(['pk' => $pkValue]));
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
        // The app's own schema (app_users/audit_log/login_attempts) is never
        // browsable or editable through the grid — otherwise an editor could
        // rewrite their own role and a viewer could read every password hash.
        // Treated exactly like a nonexistent schema so callers need no changes.
        if ($db === Database::appSchemaName()) {
            throw new InvalidArgumentException('Unknown database');
        }

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
