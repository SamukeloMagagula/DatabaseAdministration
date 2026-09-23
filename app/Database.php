<?php

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;
    private static ?string $appSchemaName = null;

    /**
     * The shared connection deliberately has NO default database.
     *
     * Anything the app runs against its own tables must name the app schema
     * explicitly (see appTable()), so that an unqualified statement typed into
     * the SQL console cannot silently land on `app_users` / `audit_log` /
     * `login_attempts` and escalate the caller's privileges.
     */
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306')
            );
            self::$connection = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        }
        return self::$connection;
    }

    /**
     * Raw (unquoted) name of the schema holding the app's own tables.
     *
     * Resolved once and then cached, so a later Config::load() cannot repoint
     * app-table queries at a different schema than the one already in use.
     */
    public static function appSchemaName(): string
    {
        if (self::$appSchemaName === null) {
            $name = Config::get('DB_APP_SCHEMA', 'dbwebui_app');
            self::$appSchemaName = ($name === null || $name === '') ? 'dbwebui_app' : $name;
        }
        return self::$appSchemaName;
    }

    /** Backtick-quoted app schema name, ready to prefix a table reference. */
    public static function appSchema(): string
    {
        return self::quote(self::appSchemaName());
    }

    /** Backtick-quoted `schema`.`table` reference for one of the app's own tables. */
    public static function appTable(string $table): string
    {
        return self::appSchema() . '.' . self::quote($table);
    }

    public static function reset(): void
    {
        self::$connection = null;
        self::$appSchemaName = null;
    }

    private static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
