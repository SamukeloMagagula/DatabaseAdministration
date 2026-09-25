<?php

declare(strict_types=1);

/** Where the real config.php lives. Defaults next to this file; override with
 *  DBADMIN_CONFIG to keep it somewhere else entirely, e.g. outside the served tree. */
define('CONFIG_PATH', getenv('DBADMIN_CONFIG') ?: __DIR__ . '/config.php');

define('DB_APP_SCHEMA', getenv('DBADMIN_APP_SCHEMA') ?: 'dbwebui_app');

/** Consecutive failed sign-ins, per username or per IP, before a lockout. */
define('LOGIN_MAX_ATTEMPTS', (int) (getenv('DBADMIN_LOGIN_MAX_ATTEMPTS') ?: 5));

/** How long a lockout lasts, and how far back "consecutive" looks. */
define('LOGIN_WINDOW_MINUTES', (int) (getenv('DBADMIN_LOGIN_WINDOW_MINUTES') ?: 15));

/** Data-grid rows per page unless the visitor asks for a different amount. */
define('GRID_PAGE_SIZE_DEFAULT', (int) (getenv('DBADMIN_PAGE_SIZE') ?: 50));

/** Hard ceiling on a requested page size, regardless of what the query string asks for. */
const GRID_PAGE_SIZE_MAX = 500;

/** True when nginx has told PHP-FPM the request arrived over TLS directly,
 *  or a reverse proxy in front of it says so via X-Forwarded-Proto. */
function using_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Requires CONFIG_PATH, failing with a clear message instead of a bare fatal. */
function load_app_config(): void
{
    if (!is_readable(CONFIG_PATH)) {
        throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
    }
    require CONFIG_PATH;
}
