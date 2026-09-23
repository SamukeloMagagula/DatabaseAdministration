<?php

namespace Tests\Integration;

use App\Auth;
use App\Controllers\AuthController;
use App\Csrf;
use App\Database;
use App\RateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    private \PDO $pdo;
    private string $appUsers;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        $this->appUsers = Database::appTable('app_users');
        $this->pdo->exec('TRUNCATE TABLE ' . Database::appTable('login_attempts'));
        $this->pdo->exec('TRUNCATE TABLE ' . Database::appTable('audit_log'));
        $this->pdo->exec("TRUNCATE TABLE {$this->appUsers}");

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->appUsers} (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)"
        );
        $stmt->execute(['u' => 'alice', 'p' => password_hash('correct-password', PASSWORD_DEFAULT), 'r' => 'admin']);

        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
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

    public function test_a_success_resets_the_consecutive_failure_streak(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));
        for ($i = 0; $i < 4; $i++) {
            $auth->attemptLogin('alice', 'wrong-password');
        }
        $this->assertTrue($auth->attemptLogin('alice', 'correct-password')['success']);

        // Four more failures: 8 failures in the window, but only 4 since the
        // success, so the "5 consecutive failures" threshold is not reached.
        for ($i = 0; $i < 4; $i++) {
            $auth->attemptLogin('alice', 'wrong-password');
        }

        $result = $auth->attemptLogin('alice', 'correct-password');

        $this->assertTrue($result['success']);
    }

    public function test_ip_identifier_is_recorded_when_supplied(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));

        $auth->attemptLogin('alice', 'wrong-password', '203.0.113.7');

        $attempts = Database::appTable('login_attempts');
        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM {$attempts} WHERE identifier = 'ip:203.0.113.7'")
            ->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_lockout_can_be_triggered_by_the_ip_dimension_alone(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));
        // Five failures from one IP, each against a different username, so no
        // single username reaches the threshold.
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin("ghost{$i}", 'wrong-password', '203.0.113.9');
        }

        $result = $auth->attemptLogin('alice', 'correct-password', '203.0.113.9');

        $this->assertFalse($result['success']);
        $this->assertSame('locked_out', $result['error']);
    }

    public function test_login_action_rejects_missing_csrf_token(): void
    {
        $_SESSION = [];
        $_POST = ['username' => 'alice', 'password' => 'correct-password'];
        $controller = new AuthController();

        $html = $controller->handleLogin();

        $this->assertStringContainsString('Invalid form submission', $html);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_action_rejects_wrong_csrf_token(): void
    {
        $_SESSION = [];
        Csrf::token();
        $_POST = ['csrf_token' => 'wrong', 'username' => 'alice', 'password' => 'correct-password'];
        $controller = new AuthController();

        $html = $controller->handleLogin();

        $this->assertStringContainsString('Invalid form submission', $html);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_action_audits_success_and_failure(): void
    {
        $controller = new AuthController();

        $_SESSION = [];
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'alice', 'password' => 'wrong-password'];
        $controller->handleLogin();

        $_SESSION = [];
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'alice', 'password' => 'correct-password'];
        $controller->handleLogin();

        $auditLog = Database::appTable('audit_log');
        $actions = $this->pdo
            ->query("SELECT action_type FROM {$auditLog} ORDER BY id")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['LOGIN_FAIL', 'LOGIN_OK'], $actions);
    }

    public function test_logout_action_rejects_missing_csrf_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $_POST = [];
        $controller = new AuthController();

        $controller->handleLogout();

        $this->assertArrayHasKey('user_id', $_SESSION);
    }

    public function test_logout_action_clears_the_session_with_a_valid_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $_POST = ['csrf_token' => Csrf::token()];
        $controller = new AuthController();

        $controller->handleLogout();

        $this->assertArrayNotHasKey('user_id', $_SESSION);
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
