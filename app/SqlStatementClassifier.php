<?php

namespace App;

final class SqlStatementClassifier
{
    private const DML = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    public static function classify(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('#^/\*.*?\*/\s*#s', '', $sql);
        $sql = preg_replace('/^(--.*(\r?\n|$))+/', '', $sql);
        $sql = ltrim($sql);

        $firstWord = strtoupper((string) strtok($sql, " \t\r\n("));

        if (in_array($firstWord, self::DML, true)) {
            return $firstWord;
        }
        if (in_array($firstWord, self::DDL_KEYWORDS, true)) {
            return 'DDL';
        }
        return 'OTHER';
    }
}
