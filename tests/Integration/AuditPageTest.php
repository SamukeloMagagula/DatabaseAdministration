<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\AuditController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AuditPageTest extends TestCase
{
    protected function setUp(): void
    {
        Database::connection()->exec('TRUNCATE TABLE audit_log');
        (new AuditLog(Database::connection()))->record(1, 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
    }

    public function test_admin_sees_the_audit_page(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringContainsString('SQL_EXEC', $html);
    }

    public function test_non_admin_is_blocked(): void
    {
        $_SESSION = ['user_id' => 2, 'username' => 'vic', 'role' => 'viewer'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringNotContainsString('SQL_EXEC', $html);
    }

    public function test_username_filter_is_applied(): void
    {
        (new AuditLog(Database::connection()))->record(2, 'bob', 'SQL_EXEC', null, null, 'SELECT 2');
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $_GET = ['username' => 'bob'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringContainsString('bob', $html);
        $this->assertStringNotContainsString('>alice<', $html);
    }
}
