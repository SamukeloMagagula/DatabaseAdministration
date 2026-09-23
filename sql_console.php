<?php

declare(strict_types=1);

// Running visitor-supplied SQL. Two locks keep a non-admin off this app's own
// tables: connect() opens with no default database, and references_app_schema()
// below rejects a qualified reference to it. Both are heuristics, not a parser —
// the durable fix is a separate MariaDB account with no grants on the app schema.

require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/sql_classifier.php';
require_once __DIR__ . '/audit.php';

function run_sql_statement(PDO $pdo, string $sql, string $role, string $username): array
{
    if ($sql === '') {
        return ['ok' => false, 'error' => 'Enter a SQL statement to run.'];
    }

    $type = classify_sql($sql);
    if (!role_can_run_statement($role, $type)) {
        audit_record($pdo, $username, 'SQL_REJECTED', null, null, $sql);
        return ['ok' => false, 'error' => "Your role is not permitted to run {$type} statements."];
    }

    if ($role !== ROLE_ADMIN && references_app_schema($sql)) {
        audit_record($pdo, $username, 'SQL_REJECTED', null, null, $sql);
        return ['ok' => false, 'error' => 'This statement references a restricted schema.'];
    }

    try {
        if ($type === 'SELECT') {
            $rows = $pdo->query($sql)->fetchAll();
            audit_record($pdo, $username, 'SQL_EXEC', null, null, $sql);
            return ['ok' => true, 'rows' => $rows, 'rowCount' => count($rows)];
        }

        $affected = $pdo->exec($sql);
        audit_record($pdo, $username, 'SQL_EXEC', null, null, $sql);
        return ['ok' => true, 'rows' => null, 'rowCount' => $affected];
    } catch (PDOException $e) {
        audit_record($pdo, $username, 'SQL_ERROR', null, null, $sql . ' -- ERROR: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Does $sql name this app's own schema as "schema.table"? Checked against
// both the raw text and a comment-stripped copy, so a comment used as
// whitespace before the dot can't hide the reference.
function references_app_schema(string $sql): bool
{
    $schema = preg_quote(DB_APP_SCHEMA, '/');
    $pattern = '/`?(?<![A-Za-z0-9_])' . $schema . '`?\s*\./i';

    return preg_match($pattern, $sql) === 1 || preg_match($pattern, strip_sql_comments($sql)) === 1;
}

// Replaces every comment in $sql with a space, for the app-schema check
// only (the executed statement is never rewritten). Tracks quote state so a
// comment marker inside a string literal is ignored, and keeps the body of
// an executable /*! ... */ comment, since MariaDB runs that rather than
// skipping it.
function strip_sql_comments(string $sql): string
{
    $out = '';
    $len = strlen($sql);
    $i = 0;
    $quote = null;

    while ($i < $len) {
        $ch = $sql[$i];

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
            $body = $end === false ? substr($sql, $i + 2) : substr($sql, $i + 2, $end - ($i + 2));
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
            if ($newline === false) break;
            $out .= "\n";
            $i = $newline + 1;
            continue;
        }

        $out .= $ch;
        $i++;
    }

    return $out;
}
