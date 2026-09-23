<?php

declare(strict_types=1);

// Template only. In production the real credentials live at the path
// CONFIG_PATH points to (settings.php), outside the served tree, so this
// checked-in copy is never the one actually loaded.
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_USER = 'dbwebui_svc';
const DB_PASS = 'changeme';

/**
 * The shared connection deliberately has NO default database.
 *
 * This app's SQL console lets an editor or viewer run a statement of their own
 * choosing over this same connection. If it defaulted to this app's own
 * schema, an *unqualified* `UPDATE app_users …` would silently land on it and
 * hand out admin. Every query this app makes against its own tables must
 * therefore name the schema explicitly — see app_table() below.
 */
function connect(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Without this, PDO_MySQL's default lets a single query() or exec()
        // run a semicolon-separated batch. The SQL console classifies only the
        // first statement's leading keyword, so `SELECT 1; DROP TABLE x;`
        // would pass a SELECT-only role check and drop the table anyway.
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
}

/** Backtick-quote an identifier, doubling any backtick it contains. */
function quote_ident(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Backtick-quoted name of the schema holding this app's own tables. */
function app_schema(): string
{
    return quote_ident(DB_APP_SCHEMA);
}

/** Backtick-quoted `schema`.`table` reference for one of this app's own tables. */
function app_table(string $table): string
{
    return app_schema() . '.' . quote_ident($table);
}
