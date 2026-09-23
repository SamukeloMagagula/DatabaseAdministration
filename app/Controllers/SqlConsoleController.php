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
     * `.` exactly like whitespace, so a comment wedged into that gap still
     * yields a reference to the app schema. A naive regex strip is not enough
     * here, because an attacker controls both copies that get checked and can
     * defeat them independently:
     *
     *  - `--` begins a comment only when whitespace (or end of line) follows.
     *    In `SELECT 1--2, x FROM app<block comment>.t` the `--2` is arithmetic,
     *    not a comment, so a strip-to-end-of-line hides the real reference from
     *    the stripped copy while the block comment hides it from the raw copy.
     *  - A comment marker inside '…', "…" or `…` is not a comment at all, so
     *    honouring it can likewise swallow a later real reference.
     *  - The body of an executable `/*!` (or `/*M!`) comment is *executed* by
     *    the server, so it must be kept rather than dropped.
     *
     * This walks the statement the way that lexer does and replaces each real
     * comment with a single space. The statement sent to the server is never
     * rewritten — this is a detection-only working copy.
     */
    private function stripCommentsForSchemaCheck(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        $quote = null;

        while ($i < $len) {
            $ch = $sql[$i];

            // Inside a quoted string literal or quoted identifier: copy through
            // verbatim, honouring backslash and doubled-quote escapes.
            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $out .= $quote;
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $body = $end === false
                    ? substr($sql, $i + 2)
                    : substr($sql, $i + 2, $end - ($i + 2));
                // Executable comment: the server runs the body, so keep it and
                // drop only the `!`/`M!` marker and any version prefix.
                if (preg_match('/^M?!/', $body) === 1) {
                    $out .= ' ' . (preg_replace('/^M?!\d{0,5}/', '', $body) ?? $body) . ' ';
                } else {
                    $out .= ' ';
                }
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            $isLineComment = $ch === '#'
                || ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-'
                    && ($i + 2 >= $len || ctype_space($sql[$i + 2]) || ord($sql[$i + 2]) < 32));

            if ($isLineComment) {
                $newline = strpos($sql, "\n", $i);
                $out .= ' ';
                if ($newline === false) {
                    break;
                }
                // The newline itself is whitespace, not part of the comment.
                $out .= "\n";
                $i = $newline + 1;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }
}
