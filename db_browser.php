<?php

declare(strict_types=1);

// What databases and tables exist, a table's columns, and database-level
// actions (copy, status). Every $db/$table from a visitor is checked against
// information_schema before use — see assert_valid_database/table below.

require_once __DIR__ . '/audit.php';

const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

/** Databases the dashboard may show: not MariaDB's own, not this app's own. */
function list_databases(PDO $pdo): array
{
    $all = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($all, SYSTEM_SCHEMAS, [DB_APP_SCHEMA]));
}

function list_tables(PDO $pdo, string $db): array
{
    assert_valid_database($pdo, $db);
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME');
    $stmt->execute(['db' => $db]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function table_columns(PDO $pdo, string $db, string $table): array
{
    assert_valid_table($pdo, $db, $table);
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION'
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
 * $db exists and is not this app's own schema. Compared case-insensitively
 * against the canonical information_schema name, since MariaDB itself
 * matches that way and a byte-exact check could be sidestepped by case alone.
 */
function assert_valid_database(PDO $pdo, string $db): void
{
    $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
    $stmt->execute(['db' => $db]);
    $canonical = $stmt->fetchColumn();
    if ($canonical === false || strcasecmp(trim((string) $canonical), DB_APP_SCHEMA) === 0) {
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

/** Only letters, numbers and underscores — required since a database name can't be bound as a placeholder. */
function valid_new_database_name(string $name): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1 && strcasecmp($name, DB_APP_SCHEMA) !== 0;
}

/** Creates $newDb as a full copy of $sourceDb: same tables, same data. */
function copy_database(PDO $pdo, string $sourceDb, string $newDb, string $username): void
{
    assert_valid_database($pdo, $sourceDb);
    if (!valid_new_database_name($newDb)) {
        throw new InvalidArgumentException('Invalid database name');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
    $stmt->execute(['db' => $newDb]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('A database with that name already exists');
    }

    $pdo->exec('CREATE DATABASE ' . quote_ident($newDb));
    foreach (list_tables($pdo, $sourceDb) as $table) {
        $pdo->exec(sprintf('CREATE TABLE `%s`.`%s` LIKE `%s`.`%s`', $newDb, $table, $sourceDb, $table));
        $pdo->exec(sprintf('INSERT INTO `%s`.`%s` SELECT * FROM `%s`.`%s`', $newDb, $table, $sourceDb, $table));
    }

    audit_record($pdo, $username, 'DB_COPY', $sourceDb, null, "copied to {$newDb}");
}

/** Server version, uptime, connection count, and size of each managed database. */
function server_status(PDO $pdo): array
{
    $uptime = $pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch();
    $connections = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();

    $sizes = $pdo->query(
        'SELECT TABLE_SCHEMA AS db, ROUND(SUM(data_length + index_length) / 1048576, 2) AS mb
         FROM information_schema.TABLES GROUP BY TABLE_SCHEMA'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $managed = list_databases($pdo);
    $sizes = array_filter($sizes, fn($db) => in_array($db, $managed, true), ARRAY_FILTER_USE_KEY);
    arsort($sizes);

    return [
        'version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        'uptime' => (int) ($uptime['Value'] ?? 0),
        'connections' => (int) ($connections['Value'] ?? 0),
        'sizes' => $sizes,
    ];
}
