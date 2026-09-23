<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/sql_console.php';

require_login();
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

$user = current_user();
$sql = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql = trim((string) ($_POST['sql'] ?? ''));

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $result = ['ok' => false, 'error' => 'Invalid form submission, please try again.'];
    } else {
        $result = run_sql_statement(connect(), $sql, $user['role'], $user['username']);
    }
}

echo render('sql_console', [
    'user' => $user,
    'csrfToken' => csrf_token(),
    'result' => $result,
    'sql' => $sql,
]);
