<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/audit.php';

require_role(ROLE_ADMIN);
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 50;
$username = $_GET['username'] ?? null;
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

echo render('audit_log', [
    'user' => current_user(),
    'entries' => audit_recent(connect(), $pageSize, ($page - 1) * $pageSize, $username ?: null, $from ?: null, $to ?: null),
    'page' => $page,
    'filters' => ['username' => $username, 'from' => $from, 'to' => $to],
]);
