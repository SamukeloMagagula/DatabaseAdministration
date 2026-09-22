<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\SqlConsoleController;
use App\Database;
use App\Roles;
use PHPUnit\Framework\TestCase;

final class SqlConsoleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec('CREATE TABLE wbtest_fixture.widgets (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->pdo->exec("INSERT INTO wbtest_fixture.widgets VALUES (1, 'bolt')");
        $this->pdo->exec('TRUNCATE TABLE audit_log');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
    }

    public function test_viewer_can_run_select(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('SELECT * FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['rowCount']);
    }

    public function test_viewer_cannot_run_insert(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement("INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", Roles::VIEWER, 1, 'vic');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('INSERT', $result['error']);
    }

    public function test_editor_can_run_insert_but_not_ddl(): void
    {
        $console = new SqlConsoleController();

        $insert = $console->runStatement("INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", Roles::EDITOR, 1, 'ed');
        $this->assertTrue($insert['ok']);

        $ddl = $console->runStatement('DROP TABLE wbtest_fixture.widgets', Roles::EDITOR, 1, 'ed');
        $this->assertFalse($ddl['ok']);
    }

    public function test_admin_can_run_ddl(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('ALTER TABLE wbtest_fixture.widgets ADD COLUMN qty INT', Roles::ADMIN, 1, 'admin1');

        $this->assertTrue($result['ok']);
    }

    public function test_every_execution_is_audited_including_rejections(): void
    {
        $console = new SqlConsoleController();
        $console->runStatement('SELECT * FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');
        $console->runStatement('DELETE FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');

        $entries = (new AuditLog($this->pdo))->recent();

        $this->assertCount(2, $entries);
        $this->assertSame('SQL_REJECTED', $entries[0]['action_type']);
        $this->assertSame('SQL_EXEC', $entries[1]['action_type']);
    }

    public function test_sql_errors_are_caught_and_audited(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('SELECT * FROM wbtest_fixture.no_such_table', Roles::ADMIN, 1, 'admin1');

        $this->assertFalse($result['ok']);
        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('SQL_ERROR', $entries[0]['action_type']);
    }

    public function test_empty_statement_is_rejected_without_touching_the_database(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('', Roles::ADMIN, 1, 'admin1');

        $this->assertFalse($result['ok']);
    }
}
