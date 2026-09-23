<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/db_browser.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/export.php';

require_login();
load_app_config();

$pdo = connect();
$user = current_user();
$db = (string) ($_GET['db'] ?? $_POST['db'] ?? '');
$table = (string) ($_GET['table'] ?? $_POST['table'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$view = (string) ($_GET['view'] ?? '');

if ($method === 'GET' && $view === 'structure') {
    echo render('table_structure', [
        'user' => $user,
        'db' => $db,
        'table' => $table,
        'columns' => table_columns($pdo, $db, $table),
    ]);
    exit;
}

if ($method === 'GET' && $view === 'new') {
    require_role(ROLE_EDITOR, ROLE_ADMIN);
    echo render('row_form', [
        'user' => $user,
        'db' => $db,
        'table' => $table,
        'columns' => table_columns($pdo, $db, $table),
        'row' => null,
        'primaryKey' => primary_key_column($pdo, $db, $table),
        'pkValue' => null,
        'csrfToken' => csrf_token(),
    ]);
    exit;
}

if ($method === 'GET' && $view === 'edit') {
    require_role(ROLE_EDITOR, ROLE_ADMIN);
    $pkColumn = primary_key_column($pdo, $db, $table);
    if ($pkColumn === null) {
        http_response_code(400);
        echo 'Table has no primary key; editing is not supported.';
        exit;
    }
    $pk = (string) ($_GET['pk'] ?? '');

    echo render('row_form', [
        'user' => $user,
        'db' => $db,
        'table' => $table,
        'columns' => table_columns($pdo, $db, $table),
        'row' => find_row($pdo, $db, $table, $pkColumn, $pk),
        'primaryKey' => $pkColumn,
        'pkValue' => $pk,
        'csrfToken' => csrf_token(),
    ]);
    exit;
}

$sortColumn = $_GET['sort'] ?? null;
$sortColumn = is_string($sortColumn) ? $sortColumn : null;
$sortDir = $_GET['dir'] ?? 'ASC';
$sortDir = is_string($sortDir) ? $sortDir : 'ASC';
$rawFilters = $_GET['filter'] ?? [];
$rawFilters = is_array($rawFilters) ? $rawFilters : [];

if ($method === 'GET' && $view === 'export_csv') {
    $columns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');
    $filters = array_filter(array_intersect_key($rawFilters, array_flip($columns)), fn($v) => is_string($v));
    $rows = all_rows($pdo, $db, $table, $sortColumn, $sortDir, $filters);
    audit_record($pdo, $user['username'], 'EXPORT_CSV', $db, $table, count($rows) . ' rows');
    stream_csv("{$table}.csv", $columns, $rows);
    exit;
}

if ($method === 'GET' && $view === 'print') {
    $columns = table_columns($pdo, $db, $table);
    $validColumns = array_column($columns, 'COLUMN_NAME');
    $filters = array_filter(array_intersect_key($rawFilters, array_flip($validColumns)), fn($v) => is_string($v));
    echo render('table_print', [
        'db' => $db,
        'table' => $table,
        'columns' => $columns,
        'rows' => all_rows($pdo, $db, $table, $sortColumn, $sortDir, $filters),
    ], null);
    exit;
}

if ($method === 'POST') {
    require_role(ROLE_EDITOR, ROLE_ADMIN);
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Invalid form submission.';
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $redirect = fn() => header('Location: /table.php?db=' . rawurlencode($db) . '&table=' . rawurlencode($table));

    if ($action === 'insert') {
        insert_row($pdo, $db, $table, $_POST['fields'] ?? [], $user['username']);
        $redirect();
        exit;
    }

    $pkColumn = primary_key_column($pdo, $db, $table);
    if ($pkColumn === null) {
        http_response_code(400);
        echo 'Table has no primary key; editing is not supported.';
        exit;
    }
    $pk = (string) ($_POST['pk'] ?? '');

    if ($action === 'update') {
        update_row($pdo, $db, $table, $pkColumn, $pk, $_POST['fields'] ?? [], $user['username']);
        $redirect();
        exit;
    }

    if ($action === 'delete') {
        delete_row($pdo, $db, $table, $pkColumn, $pk, $user['username']);
        $redirect();
        exit;
    }

    http_response_code(400);
    echo 'Unknown action.';
    exit;
}

// GET with no view — the grid itself.
$columns = table_columns($pdo, $db, $table);
$validColumns = array_column($columns, 'COLUMN_NAME');
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['page_size'] ?? GRID_PAGE_SIZE_DEFAULT);
$filters = array_filter(array_intersect_key($rawFilters, array_flip($validColumns)), fn($v) => is_string($v));

echo render('table_data', [
    'user' => $user,
    'db' => $db,
    'table' => $table,
    'columns' => $columns,
    'primaryKey' => primary_key_column($pdo, $db, $table),
    'result' => list_rows($pdo, $db, $table, $page, $pageSize, $sortColumn, $sortDir, $filters),
    'sortColumn' => $sortColumn,
    'sortDir' => $sortDir,
    'filters' => $filters,
    'csrfToken' => csrf_token(),
]);
