<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Http;
use App\Roles;
use App\View;
use PDO;
use PDOException;

final class UserController
{
    private PDO $pdo;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function index(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        return View::render('users', [
            'user' => Auth::currentUser(),
            'users' => $this->all(),
            'roles' => Roles::ALL,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function create(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($username === '' || $password === '' || !Roles::isValid($role)) {
            http_response_code(422);
            return 'Username, password, and a valid role are required.';
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM app_users WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn()) {
            http_response_code(409);
            return 'That username already exists.';
        }

        $actor = Auth::currentUser();
        try {
            $this->createUser($username, $password, $role, $actor['id'], $actor['username']);
        } catch (PDOException $e) {
            http_response_code(409);
            return 'That username already exists.';
        }

        return Http::redirect('/users');
    }

    public function updateRole(int $userId): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $role = (string) ($_POST['role'] ?? '');
        if (!Roles::isValid($role)) {
            http_response_code(422);
            return 'Invalid role.';
        }

        $actor = Auth::currentUser();
        $this->setRole($userId, $role, $actor['id'], $actor['username']);

        return Http::redirect('/users');
    }

    public function setActive(int $userId): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $active = ((string) ($_POST['active'] ?? '0')) === '1';
        $actor = Auth::currentUser();
        $this->setActiveState($userId, $active, $actor['id'], $actor['username']);

        return Http::redirect('/users');
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT id, username, role, is_active, created_at FROM app_users ORDER BY username')->fetchAll();
    }

    public function createUser(string $username, string $password, string $role, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_CREATE', null, null, "created user '{$username}' with role '{$role}'");
    }

    public function setRole(int $userId, string $role, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET role = :r WHERE id = :id');
        $stmt->execute(['r' => $role, 'id' => $userId]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_UPDATE', null, null, "set user #{$userId} role to '{$role}'");
    }

    public function setActiveState(int $userId, bool $active, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'id' => $userId]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_UPDATE', null, null, 'set user #' . $userId . ' active=' . ($active ? '1' : '0'));
    }
}
