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

        // Second layer of app-schema isolation. The shared connection has no
        // default database (Database::connection()), so an *unqualified*
        // reference to app_users/audit_log/login_attempts already fails. This
        // rejects the *qualified* form for non-admins. Like
        // SqlStatementClassifier, it is a deliberate heuristic, not a parser.
        if ($role !== Roles::ADMIN) {
            $appSchema = preg_quote(Database::appSchemaName(), '/');
            // The left lookbehind sits *after* the optional backtick so that a
            // shorter schema name cannot match as a substring of a longer
            // identifier (`myapp.t` when the app schema is `app`), while
            // `UPDATE`appschema`.t` — legal MariaDB, no space needed after a
            // keyword before a backtick — is still caught.
            $pattern = '/`?(?<![A-Za-z0-9_])' . $appSchema . '`?\s*\./i';
            // Matched against the raw text *and* a comment-stripped copy: the
            // stripped copy catches comment-as-whitespace smuggling, the raw
            // text keeps a comment marker inside a string literal from hiding a
            // later reference from the stripper. The executed $sql is untouched.
            if (preg_match($pattern, $sql) || preg_match($pattern, $this->stripCommentsForSchemaCheck($sql))) {
                $this->auditLog->record($userId, $username, 'SQL_REJECTED', null, null, $sql);
                return ['ok' => false, 'error' => 'This statement references a restricted schema.'];
            }
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

    /**
     * Comment-stripped copy of the statement, for the app-schema check only.
     *
     * MariaDB's lexer treats a comment between an identifier and the following
     * `.` exactly like whitespace, so a block or line comment wedged into that
     * gap still yields a reference to the app schema. This returns a working
     * copy for that detection pass; the statement sent to the server is never
     * rewritten.
     */
    private function stripCommentsForSchemaCheck(string $sql): string
    {
        $stripped = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $stripped = preg_replace('/--.*$/m', ' ', $stripped) ?? $stripped;
        return preg_replace('/#.*$/m', ' ', $stripped) ?? $stripped;
    }
}
