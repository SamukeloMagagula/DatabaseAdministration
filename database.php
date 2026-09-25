<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/db_browser.php';
require_once __DIR__ . '/export.php';

require_login();
load_app_config();

$pdo = connect();
$user = current_user();
$db = (string) ($_GET['db'] ?? $_POST['db'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? '') === 'export_sql') {
    assert_valid_database($pdo, $db);
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
    header('Location: database.php?db=' . rawurlencode($newDb));
    exit;
}

echo render('tables', [
    'user' => $user,
    'db' => $db,
    'tables' => list_tables($pdo, $db),
    'csrfToken' => csrf_token(),
]);
