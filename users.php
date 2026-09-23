<?php

declare(strict_types=1);

/**
 * Managing this app's own login accounts — not the databases it administers.
 *
 * Password hashing and audit logging live here rather than in manage_users.php
 * so they happen exactly once regardless of which page action triggers them.
 */

require_once __DIR__ . '/audit.php';

function all_app_users(PDO $pdo): array
{
    $table = app_table('app_users');
    return $pdo->query("SELECT id, username, role, is_active, created_at FROM {$table} ORDER BY username")->fetchAll();
}

function create_app_user(PDO $pdo, string $username, string $password, string $role, int $actorId, string $actorUsername): void
{
    $table = app_table('app_users');
    $stmt = $pdo->prepare("INSERT INTO {$table} (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)");
    $stmt->execute([
        'u' => $username,
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $role,
    ]);
    audit_record($pdo, $actorId, $actorUsername, 'USER_CREATE', null, null, "created user '{$username}' with role '{$role}'");
}

function set_user_role(PDO $pdo, int $userId, string $role, int $actorId, string $actorUsername): void
{
    $table = app_table('app_users');
    $pdo->prepare("UPDATE {$table} SET role = :r WHERE id = :id")->execute(['r' => $role, 'id' => $userId]);
    audit_record($pdo, $actorId, $actorUsername, 'USER_UPDATE', null, null, "set user #{$userId} role to '{$role}'");
}

function set_user_active(PDO $pdo, int $userId, bool $active, int $actorId, string $actorUsername): void
{
    $table = app_table('app_users');
    $pdo->prepare("UPDATE {$table} SET is_active = :a WHERE id = :id")->execute(['a' => $active ? 1 : 0, 'id' => $userId]);
    audit_record($pdo, $actorId, $actorUsername, 'USER_UPDATE', null, null, 'set user #' . $userId . ' active=' . ($active ? '1' : '0'));
}
