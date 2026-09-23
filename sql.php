<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/sql_console.php';

require_login();
load_app_config();

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
