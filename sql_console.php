<?php

declare(strict_types=1);

/**
 * Running a visitor-supplied SQL statement.
 *
 * Two independent locks stand between a non-admin and this app's own tables:
 *
 *   1. connect() (config.php) opens with no default database, so an
 *      *unqualified* `UPDATE app_users …` fails outright — there is nothing
 *      for it to fall back to.
 *   2. run_sql_statement() below additionally rejects a *qualified* reference
 *      to the app's schema for non-admins.
 *
 * Both are heuristics, not a parser, and both are named here rather than left
 * implicit: the durable fix is a second MariaDB account with no grants on
 * this app's own schema at all, used only for the grid and this console.
 * Nothing here should be read as a substitute for that.
 */

require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/sql_classifier.php';
require_once __DIR__ . '/audit.php';

function run_sql_statement(PDO $pdo, string $sql, string $role, int $userId, string $username): array
{
    if ($sql === '') {
        return ['ok' => false, 'error' => 'Enter a SQL statement to run.'];
    }

    $type = classify_sql($sql);
    if (!role_can_run_statement($role, $type)) {
        audit_record($pdo, $userId, $username, 'SQL_REJECTED', null, null, $sql);
        return ['ok' => false, 'error' => "Your role is not permitted to run {$type} statements."];
    }

    if ($role !== ROLE_ADMIN && references_app_schema($sql)) {
        audit_record($pdo, $userId, $username, 'SQL_REJECTED', null, null, $sql);
        return ['ok' => false, 'error' => 'This statement references a restricted schema.'];
    }

    try {
        if ($type === 'SELECT') {
            $rows = $pdo->query($sql)->fetchAll();
            audit_record($pdo, $userId, $username, 'SQL_EXEC', null, null, $sql);
            return ['ok' => true, 'rows' => $rows, 'rowCount' => count($rows)];
        }

        $affected = $pdo->exec($sql);
        audit_record($pdo, $userId, $username, 'SQL_EXEC', null, null, $sql);
        return ['ok' => true, 'rows' => null, 'rowCount' => $affected];
    } catch (PDOException $e) {
        audit_record($pdo, $userId, $username, 'SQL_ERROR', null, null, $sql . ' -- ERROR: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Does $sql name this app's own schema as a qualifier ("schema.table")?
 *
 * Matched against the raw text *and* a comment-stripped copy (see
 * strip_sql_comments()): the stripped copy catches a comment used as
 * whitespace between the schema name and the dot, and the raw text catches a
 * comment marker sitting inside a string literal, which would otherwise hide
 * a later real reference from the stripper. Checking either copy alone misses
 * one of the two.
 *
 * The lookbehind sits after the optional backtick so that a shorter schema
 * name cannot match as a substring of a longer identifier (`myapp.t` when the
 * app schema is `app`), while `` UPDATE`appschema`.t `` — legal MariaDB, no
 * space needed after a keyword before a backtick — is still caught.
 */
function references_app_schema(string $sql): bool
{
    $schema = preg_quote(DB_APP_SCHEMA, '/');
    $pattern = '/`?(?<![A-Za-z0-9_])' . $schema . '`?\s*\./i';

    return preg_match($pattern, $sql) === 1 || preg_match($pattern, strip_sql_comments($sql)) === 1;
}

/**
 * A copy of $sql with every comment replaced by a single space, for the
 * app-schema check only — the statement actually executed is never rewritten.
 *
 * This walks the string the way MariaDB's lexer does, because a naive
 * regex strip is not enough: an attacker controls both copies that get
 * checked and can defeat each independently.
 *
 *  - `--` begins a comment only when whitespace (or end of line) follows.
 *    In `SELECT 1--2, x FROM app<block comment>.t` the `--2` is arithmetic,
 *    not a comment, so blindly stripping to end-of-line would delete the real
 *    reference that follows it — while the block comment hides it from the
 *    raw copy at the same time.
 *  - A comment marker inside '…', "…" or `…` is not a comment at all, so
 *    honouring it can likewise swallow a later real reference.
 *  - The body of an executable `/*!` (or `/*M!`) comment is *executed* by the
 *    server, so it must be kept rather than dropped.
 */
function strip_sql_comments(string $sql): string
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
            $out .= "\n"; // the newline itself is whitespace, not part of the comment
            $i = $newline + 1;
            continue;
        }

        $out .= $ch;
        $i++;
    }

    return $out;
}
