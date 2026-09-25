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

/**
 * The URL path this app is deployed under: "" at the domain root, or
 * something like "/DatabaseAdministration" when it shares a server with
 * other tools. A relative link would only work if the browser's address bar
 * already has a trailing slash after the app's directory — a bare directory
 * URL with no trailing slash (nginx serving index.php for it internally,
 * with no redirect to add the slash) leaves the browser resolving relative
 * links against the wrong depth entirely, straight out to whatever else is
 * hosted at the true server root. Computed from where the currently running
 * script sits on disk relative to this file (settings.php, always at the
 * app root) versus its own URL, so it comes out right whether that script
 * is a root-level page or something under auth/ — not hardcoded to either.
 * Override with DBADMIN_BASE_PATH if a reverse proxy rewrites the path in a
 * way this can't see.
 */
function base_path(): string
{
    static $path = null;
    if ($path !== null) return $path;

    $override = getenv('DBADMIN_BASE_PATH');
    if ($override !== false) {
        return $path = ($override === '' ? '' : '/' . trim($override, '/'));
    }

    $appRoot = str_replace('\\', '/', (string) realpath(__DIR__));
    $scriptFile = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $scriptUrl = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    // How many directories the running script sits below the app root (0 for
    // a root-level page, 1 for something under auth/, etc.) — falls back to
    // 0 if the two paths don't share the expected prefix at all (e.g. a
    // symlinked deployment), which just reproduces the old root-only
    // behavior rather than computing something nonsensical.
    $depth = str_starts_with($scriptFile, $appRoot)
        ? substr_count(ltrim(substr($scriptFile, strlen($appRoot)), '/'), '/')
        : 0;

    $segments = explode('/', trim($scriptUrl, '/'));
    $segments = array_slice($segments, 0, max(0, count($segments) - $depth - 1));

    return $path = $segments ? '/' . implode('/', $segments) : '';
}

/** Prefixes $absolutePath (starting with "/") with base_path() — use this for
 *  every link, form action, asset reference, and redirect in the app. */
function url(string $absolutePath): string
{
    return base_path() . $absolutePath;
}

/** Serves one of assets/errors/*.html, substituting {{BASE}} for base_path()
 *  — these are plain static files, so they can't call url() themselves. */
function send_static_error_page(string $file): void
{
    $html = (string) file_get_contents(__DIR__ . '/assets/errors/' . $file);
    echo str_replace('{{BASE}}', base_path(), $html);
}
