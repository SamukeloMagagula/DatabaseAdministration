<?php

namespace App;

final class Roles
{
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    public const ALL = [self::ADMIN, self::EDITOR, self::VIEWER];

    private const STATEMENT_PERMISSIONS = [
        self::VIEWER => ['SELECT'],
        self::EDITOR => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        self::ADMIN => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'],
    ];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    public static function canRunStatementType(string $role, string $statementType): bool
    {
        if ($statementType === 'OTHER') {
            return true;
        }
        return in_array($statementType, self::STATEMENT_PERMISSIONS[$role] ?? [], true);
    }
}
