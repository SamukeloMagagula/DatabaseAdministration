<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

require_once __DIR__ . '/auth/guard.php';
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

// Checked explicitly so a misplaced config reports its own cause to the log
// instead of dying as a bare "failed to open stream" fatal.
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

echo render('dashboard', [
    'user' => $user,
    'databases' => list_databases(connect()),
]);
