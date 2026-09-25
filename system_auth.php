<?php

declare(strict_types=1);

require_once __DIR__ . '/roles.php';

// Login uses the server's own accounts, not a separate app password. Role
// comes from OS group membership: dbwebui-admin and dbwebui-editor. Anyone
// else who can log into the box gets viewer. Requires the PAM PHP extension.

const ROLE_ADMIN_GROUP = 'dbwebui-admin';
const ROLE_EDITOR_GROUP = 'dbwebui-editor';

// Must match the file created at /etc/pam.d/dbwebui (see docs/SETUP.md step
// 3). Without this, pam_auth() checks against whatever service its own
// build defaults to instead — which on a stock `pecl install pam` build
// falls through to /etc/pam.d/other, denying every login regardless of
// password. Passed by name rather than position (this build's pam_auth()
// also takes $status and $checkacctmgmt before it) so this doesn't depend
// on knowing what those default to.
const PAM_SERVICE_NAME = 'dbwebui';

function pam_authenticate(string $username, string $password): bool
{
    if (!function_exists('pam_auth')) {
        throw new RuntimeException('The PAM PHP extension (PECL pam) is not installed.');
    }
    return pam_auth($username, $password, servicename: PAM_SERVICE_NAME);
}

/** Group names $username belongs to. */
function user_groups(string $username): array
{
    $info = posix_getpwnam($username);
    if ($info === false) return [];

    $names = [];
    foreach (posix_getgrall() as $group) {
        if ($group['gid'] === $info['gid'] || in_array($username, $group['members'], true)) {
            $names[] = $group['name'];
        }
    }
    return $names;
}

/** admin if in ROLE_ADMIN_GROUP, editor if in ROLE_EDITOR_GROUP, viewer otherwise. */
function resolve_role(array $groups): string
{
    if (in_array(ROLE_ADMIN_GROUP, $groups, true)) return ROLE_ADMIN;
    if (in_array(ROLE_EDITOR_GROUP, $groups, true)) return ROLE_EDITOR;
    return ROLE_VIEWER;
}
