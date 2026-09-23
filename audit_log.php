<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/audit.php';

require_role(ROLE_ADMIN);
load_app_config();

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
