<?php

namespace App;

use PDO;

final class AdminBootstrapper
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createFirstAdmin(string $username, string $password): void
    {
        $appUsers = Database::appTable('app_users');

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$appUsers} WHERE username = :u");
        $stmt->execute(['u' => $username]);
        if ($stmt->fetchColumn()) {
            throw new \RuntimeException("User '{$username}' already exists.");
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$appUsers} (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)"
        );
        try {
            $insert->execute([
                'u' => $username,
                'p' => password_hash($password, PASSWORD_DEFAULT),
                'r' => Roles::ADMIN,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException("User '{$username}' already exists.");
        }
    }
}
