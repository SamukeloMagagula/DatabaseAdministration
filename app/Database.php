<?php

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_APP_SCHEMA', 'dbwebui_app')
            );
            self::$connection = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$connection;
    }

    public static function reset(): void
    {
        self::$connection = null;
    }
}
