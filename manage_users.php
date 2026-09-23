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
require_once __DIR__ . '/users.php';

require_role(ROLE_ADMIN);
if (!is_readable(CONFIG_PATH)) {
    throw new RuntimeException('Cannot read the database config at ' . CONFIG_PATH);
}
require CONFIG_PATH;

$pdo = connect();
$actor = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Invalid form submission.';
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($username === '' || $password === '' || !role_is_valid($role)) {
            http_response_code(422);
            echo 'Username, password, and a valid role are required.';
            exit;
        }

        $table = app_table('app_users');
        $exists = $pdo->prepare("SELECT 1 FROM {$table} WHERE username = :u");
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn()) {
            http_response_code(409);
            echo 'That username already exists.';
            exit;
        }

        try {
            create_app_user($pdo, $username, $password, $role, $actor['id'], $actor['username']);
        } catch (PDOException $e) {
            // A race with a concurrent create lands here instead of the
            // pre-check above; same outcome either way.
            http_response_code(409);
            echo 'That username already exists.';
            exit;
        }

        header('Location: /manage_users.php');
        exit;
    }

    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'set_role') {
        $role = (string) ($_POST['role'] ?? '');
        if (!role_is_valid($role)) {
            http_response_code(422);
            echo 'Invalid role.';
            exit;
        }
        set_user_role($pdo, $userId, $role, $actor['id'], $actor['username']);
        header('Location: /manage_users.php');
        exit;
    }

    if ($action === 'set_active') {
        $active = ((string) ($_POST['active'] ?? '0')) === '1';
        set_user_active($pdo, $userId, $active, $actor['id'], $actor['username']);
        header('Location: /manage_users.php');
        exit;
    }

    http_response_code(400);
    echo 'Unknown action.';
    exit;
}

echo render('users', [
    'user' => $actor,
    'users' => all_app_users($pdo),
    'roles' => ROLES_ALL,
    'csrfToken' => csrf_token(),
]);
