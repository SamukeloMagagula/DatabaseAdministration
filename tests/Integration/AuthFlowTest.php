<?php

namespace Tests\Integration;

use App\Auth;
use App\Database;
use App\RateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('TRUNCATE TABLE login_attempts');
        $this->pdo->exec('TRUNCATE TABLE app_users');

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $stmt->execute(['u' => 'alice', 'p' => password_hash('correct-password', PASSWORD_DEFAULT), 'r' => 'admin']);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_successful_login_sets_session(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));

        $result = $auth->attemptLogin('alice', 'correct-password');

        $this->assertTrue($result['success']);
        $this->assertSame('alice', $_SESSION['username']);
        $this->assertSame('admin', $_SESSION['role']);
    }

    public function test_failed_login_does_not_set_session(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));

        $result = $auth->attemptLogin('alice', 'wrong-password');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_credentials', $result['error']);
        $this->assertArrayNotHasKey('username', $_SESSION);
    }

    public function test_lockout_after_five_failed_attempts(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong-password');
        }

        $result = $auth->attemptLogin('alice', 'correct-password');

        $this->assertFalse($result['success']);
        $this->assertSame('locked_out', $result['error']);
    }

    public function test_current_user_reads_from_session(): void
    {
        $_SESSION = ['user_id' => 7, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertSame(['id' => 7, 'username' => 'bob', 'role' => 'viewer'], Auth::currentUser());
    }

    public function test_current_user_is_null_when_logged_out(): void
    {
        $_SESSION = [];

        $this->assertNull(Auth::currentUser());
    }

    public function test_require_login_returns_non_null_when_logged_out(): void
    {
        $_SESSION = [];

        $this->assertNotNull(Auth::requireLogin());
    }

    public function test_require_login_returns_null_when_logged_in(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertNull(Auth::requireLogin());
    }

    public function test_require_role_blocks_wrong_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertNotNull(Auth::requireRole('admin'));
    }

    public function test_require_role_allows_matching_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];

        $this->assertNull(Auth::requireRole('admin'));
    }
}
