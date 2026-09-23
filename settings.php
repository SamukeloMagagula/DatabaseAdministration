<?php

declare(strict_types=1);

/**
 * Every path and tunable this app depends on, in one place.
 *
 * Each can be overridden by an environment variable, which is what makes a
 * local checkout runnable: point CONFIG_PATH at a file you own and the same
 * code works off the server. The defaults match the checked-in layout, so a
 * fresh clone needs no environment at all beyond a real config.php.
 *
 *   DBADMIN_CONFIG               path to the config.php holding DB credentials
 *   DBADMIN_APP_SCHEMA           schema holding this app's own tables
 *   DBADMIN_LOGIN_MAX_ATTEMPTS   consecutive failures before a lockout
 *   DBADMIN_LOGIN_WINDOW_MINUTES how long a lockout lasts
 *   DBADMIN_PAGE_SIZE            default data-grid rows per page
 */

/** Absolute path to the deployed config.php. Outside the served tree in production. */
define('CONFIG_PATH', getenv('DBADMIN_CONFIG') ?: __DIR__ . '/config.php');

/**
 * Schema holding this app's own tables (app_users, login_attempts, audit_log).
 *
 * connect() (config.php) deliberately opens with no default database, so every
 * query this app makes against its own tables must name this schema
 * explicitly — see app_table() in config.php for why.
 */
define('DB_APP_SCHEMA', getenv('DBADMIN_APP_SCHEMA') ?: 'dbwebui_app');

/** Consecutive failed sign-ins, per username or per IP, before a lockout. */
define('LOGIN_MAX_ATTEMPTS', (int) (getenv('DBADMIN_LOGIN_MAX_ATTEMPTS') ?: 5));

/** How long a lockout lasts, and how far back "consecutive" looks. */
define('LOGIN_WINDOW_MINUTES', (int) (getenv('DBADMIN_LOGIN_WINDOW_MINUTES') ?: 15));

/** Data-grid rows per page unless the visitor asks for a different amount. */
define('GRID_PAGE_SIZE_DEFAULT', (int) (getenv('DBADMIN_PAGE_SIZE') ?: 50));

/** Hard ceiling on a requested page size, regardless of what the query string asks for. */
const GRID_PAGE_SIZE_MAX = 500;
