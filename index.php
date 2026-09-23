<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db_browser.php';

$user = current_user();

if ($user === null) {
    // A one-shot message from auth.php's bounce(), if a sign-in just failed.
    $error = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_error']);

    echo render('login', ['csrfToken' => csrf_token(), 'error' => $error], null);
    exit;
}

load_app_config();

echo render('dashboard', [
    'user' => $user,
    'databases' => list_databases(connect()),
]);
