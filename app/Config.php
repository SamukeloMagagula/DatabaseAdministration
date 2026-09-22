<?php

namespace App;

final class Config
{
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!file_exists($envFile)) {
            throw new \RuntimeException("Config file not found: {$envFile}");
        }

        self::$values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            self::$values[trim($key)] = trim($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }
}
