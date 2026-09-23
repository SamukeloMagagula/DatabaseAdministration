<?php

namespace App;

use PDO;

final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * True once the identifier has accumulated MAX_ATTEMPTS failures that are
     * both inside the lockout window AND newer than its most recent success —
     * i.e. "consecutive" failures, as the spec requires. A successful login
     * therefore resets the streak.
     */
    public function isLockedOut(string $identifier): bool
    {
        $attempts = Database::appTable('login_attempts');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$attempts}
             WHERE identifier = :id AND succeeded = 0
               AND created_at > (NOW() - INTERVAL " . self::LOCKOUT_MINUTES . " MINUTE)
               AND created_at > COALESCE(
                   (SELECT MAX(created_at) FROM {$attempts} WHERE identifier = :id2 AND succeeded = 1),
                   '1970-01-01 00:00:00'
               )"
        );
        $stmt->execute(['id' => $identifier, 'id2' => $identifier]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordAttempt(string $identifier, bool $succeeded): void
    {
        $attempts = Database::appTable('login_attempts');
        $stmt = $this->pdo->prepare("INSERT INTO {$attempts} (identifier, succeeded) VALUES (:id, :s)");
        $stmt->execute(['id' => $identifier, 's' => $succeeded ? 1 : 0]);
    }
}
