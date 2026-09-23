<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/db_browser.php';
require_once __DIR__ . '/export.php';

require_login();
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

$pdo = connect();
$user = current_user();
$db = (string) ($_GET['db'] ?? $_POST['db'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? '') === 'export_sql') {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $db . '.sql"');
    audit_record($pdo, $user['username'], 'EXPORT_SQL', $db, null, 'full database dump');
    echo sql_dump_database($pdo, $db);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy') {
    require_role(ROLE_ADMIN);
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Invalid form submission.';
        exit;
    }
    $newDb = trim((string) ($_POST['new_db'] ?? ''));
    copy_database($pdo, $db, $newDb, $user['username']);
    header('Location: /database.php?db=' . rawurlencode($newDb));
    exit;
}

echo render('tables', [
    'user' => $user,
    'db' => $db,
    'tables' => list_tables($pdo, $db),
    'csrfToken' => csrf_token(),
]);
