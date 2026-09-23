<?php

declare(strict_types=1);

/**
 * Login throttling, kept separate from auth.php rather than inlined into it.
 *
 * Most of this app's action scripts hold their logic inline, on the reasoning
 * that a file with one caller does not need its own module. This is the
 * exception: which failures count as "consecutive," and counting them on both
 * the username and the IP, are exactly the kind of decision that is easy to
 * get subtly wrong and where being wrong announces itself to nobody — so it
 * gets to be a pure function with tests of its own, the same reasoning
 * permissions.php gives for staying out of api.php.
 */

/**
 * True once $identifier has LOGIN_MAX_ATTEMPTS failures that are both inside
 * the lockout window AND newer than its most recent success — i.e.
 * *consecutive* failures. A successful login resets the streak.
 */
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
