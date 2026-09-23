<?php

declare(strict_types=1);

/**
 * The three roles and what each may do.
 *
 * Enforced entirely here, in application code — every request runs as the
 * same MariaDB service account (config.php), so this is the only gate a
 * mistaken role check has.
 */

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

/**
 * May $role run a SQL statement classify_sql() calls $statementType?
 *
 * `OTHER` — anything classify_sql() doesn't recognise as DML, DDL or a
 * read-only meta-command — requires admin. It used to be allowed for every
 * role, on the reasoning that an unrecognised statement was probably a
 * harmless `SHOW`/`DESCRIBE`. It is also what a genuinely mutating statement
 * classify_sql() has no keyword for (`REPLACE INTO`, `CALL`, `LOCK TABLES`)
 * falls through to, so that reasoning let a viewer mutate data. Defaulting to
 * the safe side costs admin nothing and closes that.
 */
function role_can_run_statement(string $role, string $statementType): bool
{
    if ($statementType === 'OTHER') {
        return $role === ROLE_ADMIN;
    }
    return in_array($statementType, ROLE_STATEMENT_PERMISSIONS[$role] ?? [], true);
}
