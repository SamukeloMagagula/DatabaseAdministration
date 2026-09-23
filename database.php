<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/db_browser.php';

require_login();
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

$db = (string) ($_GET['db'] ?? '');
$pdo = connect();

echo render('tables', [
    'user' => current_user(),
    'db' => $db,
    'tables' => list_tables($pdo, $db),
]);
