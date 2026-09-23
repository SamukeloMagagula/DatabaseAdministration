<?php

declare(strict_types=1);

/** Absolute path to the deployed config.php. Outside the served tree in production. */
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
