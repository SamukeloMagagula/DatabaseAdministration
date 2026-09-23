<?php

declare(strict_types=1);

require_once __DIR__ . '/../settings.php';

/** The signed-in visitor's username/role, or null when nobody is signed in. */
function current_user(): ?array
{
    if (empty($_SESSION['username'])) {
        return null;
    }
    return [
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
    if (in_array(current_user()['role'], $roles, true)) return;

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../assets/errors/403.html');
    exit;
}
