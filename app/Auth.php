<?php

namespace App;

use PDO;

final class Auth
{
    public function __construct(private PDO $pdo, private RateLimiter $rateLimiter)
    {
    }

    public function attemptLogin(string $username, string $password): array
    {
        if ($this->rateLimiter->isLockedOut($username)) {
            return ['success' => false, 'error' => 'locked_out'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, is_active FROM app_users WHERE username = :u'
        );
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        $valid = $user && (bool) $user['is_active'] && password_verify($password, $user['password_hash']);
        $this->rateLimiter->recordAttempt($username, $valid);

        if (!$valid) {
            return ['success' => false, 'error' => 'invalid_credentials'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return ['success' => true];
    }

    public static function currentUser(): ?array
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

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireLogin(): ?string
    {
        if (self::currentUser() === null) {
            return Http::redirect('/login');
        }
        return null;
    }

    public static function requireRole(string ...$roles): ?string
    {
        $user = self::currentUser();
        if ($user === null) {
            return Http::redirect('/login');
        }
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            return View::render('error_403', ['user' => $user]);
        }
        return null;
    }
}
