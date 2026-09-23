<?php

namespace Tests\Integration;

use App\AdminBootstrapper;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AdminBootstrapperTest extends TestCase
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
    }

    public function test_creates_an_admin_user(): void
    {
        $bootstrapper = new AdminBootstrapper($this->pdo);

        $bootstrapper->createFirstAdmin('root', 'super-secret');

        $row = $this->pdo->query("SELECT * FROM {$this->appUsers} WHERE username = 'root'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('admin', $row['role']);
        $this->assertTrue(password_verify('super-secret', $row['password_hash']));
    }

    public function test_refuses_to_create_a_duplicate_username(): void
    {
        $bootstrapper = new AdminBootstrapper($this->pdo);
        $bootstrapper->createFirstAdmin('root', 'super-secret');

        $this->expectException(\RuntimeException::class);
        $bootstrapper->createFirstAdmin('root', 'another-password');
    }
}
