<?php

declare(strict_types=1);

// Login throttling, kept separate from auth/auth.php so the "consecutive
// failures" logic is a pure function with tests of its own rather than
// buried inline where a subtle mistake would go unnoticed.

// True once $identifier has LOGIN_MAX_ATTEMPTS failures inside the lockout
// window that are newer than its most recent success, i.e. consecutive.
function login_locked_out(PDO $pdo, string $identifier): bool
{
    $attempts = app_table('login_attempts');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$attempts}
         WHERE identifier = :id AND succeeded = 0
           AND created_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
           AND created_at > COALESCE(
               (SELECT MAX(created_at) FROM {$attempts} WHERE identifier = :id2 AND succeeded = 1),
               '1970-01-01 00:00:00'
           )"
    );
    $stmt->execute(['id' => $identifier, 'id2' => $identifier]);

    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(PDO $pdo, string $identifier, bool $succeeded): void
{
    $attempts = app_table('login_attempts');
    $stmt = $pdo->prepare("INSERT INTO {$attempts} (identifier, succeeded) VALUES (:id, :s)");
    $stmt->execute(['id' => $identifier, 's' => $succeeded ? 1 : 0]);
}
