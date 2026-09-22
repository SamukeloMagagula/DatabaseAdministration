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

    public function isLockedOut(string $identifier): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :id AND succeeded = 0
               AND created_at > (NOW() - INTERVAL ' . self::LOCKOUT_MINUTES . ' MINUTE)'
        );
        $stmt->execute(['id' => $identifier]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordAttempt(string $identifier, bool $succeeded): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (:id, :s)');
        $stmt->execute(['id' => $identifier, 's' => $succeeded ? 1 : 0]);
    }
}
