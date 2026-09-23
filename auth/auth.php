<?php

declare(strict_types=1);

require_once __DIR__ . '/../settings.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => using_https(),
]);
session_start();

function config_failure(string $message): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit($message);
}

if (!is_readable(CONFIG_PATH)) {
    config_failure('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;
if (!function_exists('connect')) {
    config_failure(CONFIG_PATH . ' does not define connect(): PDO.');
}
require __DIR__ . '/../csrf.php';
require __DIR__ . '/../audit.php';
require __DIR__ . '/../ratelimit.php';
require __DIR__ . '/../system_auth.php';

function bounce(string $error): void
{
    $_SESSION['flash_error'] = $error;
    header('Location: /index.php');
    exit;
}

function start_session_for(string $username, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    $_SESSION['role']     = $role;
    header('Location: /index.php');
    exit;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

$action = $_POST['action'] ?? '';

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    bounce('Your session expired — please try again.');
}

$pdo = connect();

if ($action === 'logout') {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    header('Location: /index.php');
    exit;
}

if ($action === 'login') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();

    if (login_locked_out($pdo, $username) || login_locked_out($pdo, 'ip:' . $ip)) {
        audit_record($pdo, $username, 'LOGIN_LOCKOUT', null, null, 'too many attempts');
        bounce('Too many failed attempts. Try again in a few minutes.');
    }

    $valid = $username !== '' && $password !== '' && pam_authenticate($username, $password);
    record_login_attempt($pdo, $username, $valid);
    record_login_attempt($pdo, 'ip:' . $ip, $valid);

    if (!$valid) {
        audit_record($pdo, $username, 'LOGIN_FAIL', null, null, 'invalid credentials');
        bounce('Invalid username or password.');
    }

    $role = resolve_role(user_groups($username));
    audit_record($pdo, $username, 'LOGIN_OK', null, null, "role: {$role}");
    start_session_for($username, $role);
}

bounce('Unknown action.');
