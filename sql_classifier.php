<?php

declare(strict_types=1);

// Classifies a statement's leading keyword for role_can_run_statement(). A
// heuristic, not a parser, so sql_console.php's app-schema guard exists
// separately rather than relying on this alone.

const SQL_DML_KEYWORDS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
const SQL_DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];
// SHOW/DESCRIBE/DESC report on the schema and cannot mutate data. EXPLAIN is
// deliberately absent: MariaDB's legacy EXPLAIN ANALYZE form on an
// UPDATE/DELETE actually runs the statement, so an "explain" is not always
// read-only the way SHOW and DESCRIBE always are.
const SQL_READONLY_KEYWORDS = ['SHOW', 'DESCRIBE', 'DESC'];

function classify_sql(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('#^/\*.*?\*/\s*#s', '', $sql);
    $sql = preg_replace('/^(--.*(\r?\n|$))+/', '', $sql);
    $sql = ltrim($sql);

    $firstWord = strtoupper((string) strtok($sql, " \t\r\n("));

    if (in_array($firstWord, SQL_DML_KEYWORDS, true)) return $firstWord;
    if (in_array($firstWord, SQL_DDL_KEYWORDS, true)) return 'DDL';
    if (in_array($firstWord, SQL_READONLY_KEYWORDS, true)) return 'SELECT';
    return 'OTHER';
}
