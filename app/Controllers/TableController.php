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
