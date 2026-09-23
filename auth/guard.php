<?php

declare(strict_types=1);

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../view.php';

/** The signed-in visitor's id/username/role, or null when nobody is signed in. */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) $_SESSION['username'],
        'role' => (string) $_SESSION['role'],
    ];
}

/** Ensure the visitor is signed in, or send them to the login page and stop. */
function require_login(): void
{
    if (current_user() !== null) return;
    header('Location: /index.php');
    exit;
}

/** Ensure the visitor holds one of $roles, or answer 403 and stop. Implies require_login(). */
function require_role(string ...$roles): void
{
    require_login();
    $user = current_user();
    if (in_array($user['role'], $roles, true)) return;

    http_response_code(403);
    echo render('error_403', ['user' => $user], null);
    exit;
}
