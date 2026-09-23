<?php

declare(strict_types=1);

/**
 * What databases and tables exist, and what a table's columns look like.
 *
 * Every function here that takes a $db or $table from the visitor validates it
 * against information_schema before using it — see assert_valid_database() and
 * assert_valid_table(). Callers must not skip straight to a query.
 */

const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

/** Databases the dashboard may show: everything except MariaDB's own and this app's own. */
function list_databases(PDO $pdo): array
{
    $all = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($all, SYSTEM_SCHEMAS, [DB_APP_SCHEMA]));
}

function list_tables(PDO $pdo, string $db): array
{
    assert_valid_database($pdo, $db);

    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME'
    );
    $stmt->execute(['db' => $db]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function table_columns(PDO $pdo, string $db, string $table): array
{
    assert_valid_table($pdo, $db, $table);

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute(['db' => $db, 't' => $table]);

    return $stmt->fetchAll();
}

function primary_key_column(PDO $pdo, string $db, string $table): ?string
{
    foreach (table_columns($pdo, $db, $table) as $col) {
        if ($col['COLUMN_KEY'] === 'PRI') return $col['COLUMN_NAME'];
    }
    return null;
}

/**
 * $db is a real, browsable database: it exists, and it is not this app's own
 * schema.
 *
 * The canonical name is resolved from information_schema first and compared
 * case-insensitively, rather than comparing the caller's $db directly: every
 * information_schema lookup below matches under the server's own
 * case-insensitive, trailing-space-insensitive collation, so a byte-exact
 * check on the raw input would let `DBWEBUI_APP` skip this guard while still
 * resolving against information_schema — reopening the app's own tables to
 * the grid under a different case.
 *
 * A nonexistent database and the app's own schema throw the same exception so
 * that neither leaks which one it was.
 */
function assert_valid_database(PDO $pdo, string $db): void
{
    $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
    $stmt->execute(['db' => $db]);
    $canonical = $stmt->fetchColumn();
    if ($canonical === false) {
        throw new InvalidArgumentException('Unknown database');
    }

    if (strcasecmp(trim((string) $canonical), DB_APP_SCHEMA) === 0) {
        throw new InvalidArgumentException('Unknown database');
    }
}

function assert_valid_table(PDO $pdo, string $db, string $table): void
{
    assert_valid_database($pdo, $db);

    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t');
    $stmt->execute(['db' => $db, 't' => $table]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Unknown table');
    }
}
