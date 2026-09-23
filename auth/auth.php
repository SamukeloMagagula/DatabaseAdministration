<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
session_start();

require_once __DIR__ . '/../settings.php';

function config_failure(string $message): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit($message);
}

// Checked explicitly so a misplaced config reports its own cause instead of
// dying as a bare "failed to open stream" fatal on the sign-in form.
if (!is_readable(CONFIG_PATH)) {
    config_failure('Cannot read the database config at ' . CONFIG_PATH
        . ' — check it exists and is readable by the web server user.');
}
require CONFIG_PATH;
if (!function_exists('connect')) {
    config_failure(CONFIG_PATH . ' was loaded but does not define connect(): PDO.');
}
require __DIR__ . '/../csrf.php';
require __DIR__ . '/../audit.php';
require __DIR__ . '/../ratelimit.php';

/** Store a one-shot error and bounce back to the login page. */
function bounce(string $error): void
{
    $_SESSION['flash_error'] = $error;
    header('Location: /index.php');
    exit;
}

/** Mark the session as signed in and go to the dashboard. */
function start_session_for(int $userId, string $username, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']  = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['role']     = $role;
    header('Location: /index.php');
    exit;
}

/** The caller's address, for rate limiting. Not trusted for anything else. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/**
 * The first 64 valid UTF-8 characters of the submitted username, for the audit
 * trail. Never the password. Clamped so an oversized or malformed submission
 * cannot fail the audit_log.username insert (preg_replace returns null on
 * invalid UTF-8, which casts to '').
 */
function auditable_username(string $submitted): string
{
    return (string) preg_replace('/^(.{0,64}).*$/us', '$1', $submitted);
}

$action = $_POST['action'] ?? '';

// Sign-in and sign-out are both state-changing form posts, so both carry the
// session token. Without this, a page on another site could sign a visitor
// out, or into an account the attacker controls.
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
    $ip       = client_ip();
    $attempted = auditable_username($username);

    if (login_locked_out($pdo, $username) || login_locked_out($pdo, 'ip:' . $ip)) {
        audit_record($pdo, null, $attempted, 'LOGIN_LOCKOUT', null, null, "login attempt for '{$attempted}'");
        bounce('Too many failed attempts. Try again in a few minutes.');
    }

    $appUsers = app_table('app_users');
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active FROM {$appUsers} WHERE username = :u");
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    $valid = $user && (bool) $user['is_active'] && password_verify($password, $user['password_hash']);
    record_login_attempt($pdo, $username, $valid);
    record_login_attempt($pdo, 'ip:' . $ip, $valid);

    if (!$valid) {
        audit_record($pdo, null, $attempted, 'LOGIN_FAIL', null, null, "login attempt for '{$attempted}'");
        bounce('Invalid username or password.');
    }

    audit_record($pdo, (int) $user['id'], $user['username'], 'LOGIN_OK', null, null, "login attempt for '{$attempted}'");
    start_session_for((int) $user['id'], $user['username'], $user['role']);
}

bounce('Unknown action.');
