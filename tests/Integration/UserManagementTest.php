<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\UserController;
use App\Csrf;
use App\Database;
use PHPUnit\Framework\TestCase;

final class UserManagementTest extends TestCase
{
    private \PDO $pdo;
    private string $appUsers;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        $this->appUsers = Database::appTable('app_users');
        $this->pdo->exec("TRUNCATE TABLE {$this->appUsers}");
        $this->pdo->exec('TRUNCATE TABLE ' . Database::appTable('audit_log'));
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    public function test_create_user_hashes_password_and_logs(): void
    {
        $controller = new UserController();

        $controller->createUser('bob', 'secret123', 'editor', 1, 'admin1');

        $row = $this->pdo->query("SELECT * FROM {$this->appUsers} WHERE username = 'bob'")->fetch();
        $this->assertNotFalse($row);
        $this->assertTrue(password_verify('secret123', $row['password_hash']));
        $this->assertSame('editor', $row['role']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('USER_CREATE', $entries[0]['action_type']);
    }

    public function test_set_role_changes_role(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $userId = (int) $this->pdo->query("SELECT id FROM {$this->appUsers} WHERE username = 'bob'")->fetchColumn();

        $controller->setRole($userId, 'admin', 1, 'admin1');

        $role = $this->pdo->query("SELECT role FROM {$this->appUsers} WHERE id = {$userId}")->fetchColumn();
        $this->assertSame('admin', $role);
    }

    public function test_set_active_state_disables_a_user(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $userId = (int) $this->pdo->query("SELECT id FROM {$this->appUsers} WHERE username = 'bob'")->fetchColumn();

        $controller->setActiveState($userId, false, 1, 'admin1');

        $active = (int) $this->pdo->query("SELECT is_active FROM {$this->appUsers} WHERE id = {$userId}")->fetchColumn();
        $this->assertSame(0, $active);
    }

    public function test_create_action_rejects_duplicate_username(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'bob', 'password' => 'other-pass', 'role' => 'viewer'];

        $controller->create();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->appUsers} WHERE username = 'bob'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_create_action_blocked_for_non_admin(): void
    {
        $_SESSION = ['user_id' => 2, 'username' => 'ed', 'role' => 'editor'];
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'carol', 'password' => 'secret123', 'role' => 'viewer'];
        $controller = new UserController();

        $controller->create();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->appUsers} WHERE username = 'carol'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_index_lists_users(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');

        $html = $controller->index();

        $this->assertStringContainsString('bob', $html);
    }
}
