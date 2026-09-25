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

// Every page's JS lives in an external file under assets/, never inline, so
// script-src 'self' covers all of them with no nonce needed. style-src allows
// 'unsafe-inline' only for table_print.php's own <style> block.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; "
    . "frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/auth/guard.php';
