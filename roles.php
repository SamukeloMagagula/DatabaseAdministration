<?php

declare(strict_types=1);

// The three roles and what each may do. Enforced here in application code —
// every request runs as the same MariaDB service account (config.php), so
// this is the only gate a mistaken role check has.

const ROLE_ADMIN = 'admin';
const ROLE_EDITOR = 'editor';
const ROLE_VIEWER = 'viewer';

const ROLES_ALL = [ROLE_ADMIN, ROLE_EDITOR, ROLE_VIEWER];

const ROLE_STATEMENT_PERMISSIONS = [
    ROLE_VIEWER => ['SELECT'],
    ROLE_EDITOR => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    ROLE_ADMIN  => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'],
];

function role_is_valid(string $role): bool
{
    return in_array($role, ROLES_ALL, true);
}

// OTHER (anything classify_sql() can't place as DML, DDL, or read only)
// requires admin: it's also what an unrecognised mutation like REPLACE INTO
// or CALL falls through to, so treating it as harmless let a viewer mutate data.
function role_can_run_statement(string $role, string $statementType): bool
{
    if ($statementType === 'OTHER') {
        return $role === ROLE_ADMIN;
    }
    return in_array($statementType, ROLE_STATEMENT_PERMISSIONS[$role] ?? [], true);
}
