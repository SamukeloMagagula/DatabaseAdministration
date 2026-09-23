<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/errors.php';
install_error_handler();

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => using_https(),
]);
session_start();

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/auth/guard.php';
