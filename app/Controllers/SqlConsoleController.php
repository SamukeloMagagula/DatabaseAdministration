<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Roles;
use App\SqlStatementClassifier;
use App\View;
use PDO;
use PDOException;

final class SqlConsoleController
{
    private PDO $pdo;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function show(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        return View::render('sql_console', [
            'user' => Auth::currentUser(),
            'csrfToken' => Csrf::token(),
            'result' => null,
            'sql' => '',
        ]);
    }

    public function execute(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $user = Auth::currentUser();

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return View::render('sql_console', [
                'user' => $user,
                'csrfToken' => Csrf::token(),
                'result' => ['ok' => false, 'error' => 'Invalid form submission, please try again.'],
                'sql' => (string) ($_POST['sql'] ?? ''),
            ]);
        }

        $sql = trim((string) ($_POST['sql'] ?? ''));
        $result = $this->runStatement($sql, $user['role'], $user['id'], $user['username']);

        return View::render('sql_console', [
            'user' => $user,
            'csrfToken' => Csrf::token(),
            'result' => $result,
            'sql' => $sql,
        ]);
    }

    public function runStatement(string $sql, string $role, int $userId, string $username): array
    {
        if ($sql === '') {
            return ['ok' => false, 'error' => 'Enter a SQL statement to run.'];
        }

        $type = SqlStatementClassifier::classify($sql);
        if (!Roles::canRunStatementType($role, $type)) {
            $this->auditLog->record($userId, $username, 'SQL_REJECTED', null, null, $sql);
            return ['ok' => false, 'error' => "Your role is not permitted to run {$type} statements."];
        }

        try {
            if ($type === 'SELECT') {
                $stmt = $this->pdo->query($sql);
                $rows = $stmt->fetchAll();
                $this->auditLog->record($userId, $username, 'SQL_EXEC', null, null, $sql);
                return ['ok' => true, 'rows' => $rows, 'rowCount' => count($rows)];
            }

            $affected = $this->pdo->exec($sql);
            $this->auditLog->record($userId, $username, 'SQL_EXEC', null, null, $sql);
            return ['ok' => true, 'rows' => null, 'rowCount' => $affected];
        } catch (PDOException $e) {
            $this->auditLog->record($userId, $username, 'SQL_ERROR', null, null, $sql . ' -- ERROR: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
