<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\SqlConsoleController;
use App\Csrf;
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
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        $this->pdo->exec('TRUNCATE TABLE ' . Database::appTable('audit_log'));

        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
        $_POST = [];
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

    public function test_viewer_cannot_bypass_role_gate_with_unrecognized_statement(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement(
            "REPLACE INTO wbtest_fixture.widgets VALUES (99, 'pwned')",
            Roles::VIEWER,
            1,
            'vic'
        );

        $this->assertFalse($result['ok']);
        $smuggled = (int) $this->pdo->query('SELECT COUNT(*) FROM wbtest_fixture.widgets WHERE id = 99')->fetchColumn();
        $this->assertSame(0, $smuggled);
    }

    public function test_stacked_statement_does_not_execute_second_statement(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement(
            'SELECT 1; DROP TABLE wbtest_fixture.widgets;',
            Roles::ADMIN,
            1,
            'admin1'
        );

        $this->assertFalse($result['ok']);
        $stillThere = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'wbtest_fixture' AND TABLE_NAME = 'widgets'"
        )->fetchColumn();
        $this->assertSame(1, $stillThere);
    }

    public function test_non_admin_cannot_name_the_app_schema(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        $result = $console->runStatement(
            "UPDATE `{$appSchema}`.app_users SET role = 'admin' WHERE username = 'ed'",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('restricted schema', $result['error']);
        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('SQL_REJECTED', $entries[0]['action_type']);
    }

    public function test_non_admin_cannot_hide_the_app_schema_behind_a_comment(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        // MariaDB's lexer treats a comment in this position exactly like
        // whitespace, so both variants still reference the app's own table.
        $block = $console->runStatement(
            "UPDATE {$appSchema}/**/.app_users SET role = 'admin' WHERE username = 'ed'",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($block['ok']);
        $this->assertStringContainsString('restricted schema', $block['error']);

        $line = $console->runStatement(
            "UPDATE {$appSchema} -- x\n.app_users SET role = 'admin' WHERE username = 'ed'",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($line['ok']);
        $this->assertStringContainsString('restricted schema', $line['error']);
    }

    /**
     * `--` only starts a comment in MariaDB when whitespace follows it, so
     * `1--2` is arithmetic. A stripper that truncates the line anyway would
     * delete the real reference that follows, and a block comment defeats the
     * raw-text pass, so both passes had to miss this for it to execute.
     */
    public function test_non_admin_cannot_hide_the_app_schema_behind_a_fake_line_comment(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        $result = $console->runStatement(
            "SELECT 1--2 AS x, u.* FROM {$appSchema}/**/.app_users u",
            Roles::VIEWER,
            1,
            'vic'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('restricted schema', $result['error']);
    }

    public function test_non_admin_cannot_hide_the_app_schema_behind_a_string_literal(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        // The '-- x' is a string literal, not a comment, so the reference that
        // follows it is real.
        $result = $console->runStatement(
            "SELECT '-- x' AS note, u.* FROM {$appSchema}/**/.app_users u",
            Roles::VIEWER,
            1,
            'vic'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('restricted schema', $result['error']);
    }

    public function test_non_admin_cannot_reach_the_app_schema_through_an_executable_comment(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        // MariaDB *executes* the body of a /*! ... */ comment, so dropping it
        // would hide a live reference to the app schema.
        $result = $console->runStatement(
            "UPDATE /*!{$appSchema}*/.app_users SET role = 'admin' WHERE username = 'ed'",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('restricted schema', $result['error']);
    }

    public function test_a_reference_inside_a_string_literal_still_trips_the_guard(): void
    {
        $console = new SqlConsoleController();
        $appSchema = Database::appSchemaName();

        // A '#' inside a literal is not a comment, so the qualified reference
        // later in the statement is real and must still be rejected.
        $result = $console->runStatement(
            "UPDATE wbtest_fixture.widgets SET name = '#' WHERE id = "
                . "(SELECT id FROM {$appSchema}.app_users LIMIT 1)",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('restricted schema', $result['error']);
    }

    public function test_a_trailing_comment_does_not_block_a_legitimate_statement(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement(
            "SELECT * FROM wbtest_fixture.widgets -- just a note\n",
            Roles::VIEWER,
            1,
            'vic'
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['rowCount']);
    }

    public function test_unqualified_statement_cannot_reach_the_app_schema(): void
    {
        $console = new SqlConsoleController();

        // The shared connection has no default database, so an unqualified
        // table name resolves to nothing rather than to the app's own schema.
        $result = $console->runStatement(
            "UPDATE app_users SET role = 'admin' WHERE username = 'ed'",
            Roles::EDITOR,
            1,
            'ed'
        );

        $this->assertFalse($result['ok']);
        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('SQL_ERROR', $entries[0]['action_type']);
    }

    public function test_execute_action_rejects_bad_csrf_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        Csrf::token();
        $_POST = ['csrf_token' => 'wrong', 'sql' => "INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')"];
        $console = new SqlConsoleController();

        $html = $console->execute();

        $this->assertStringContainsString('Invalid form submission', $html);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wbtest_fixture.widgets')->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertCount(0, (new AuditLog($this->pdo))->recent());
    }

    public function test_execute_action_rejects_missing_csrf_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = ['sql' => "INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')"];
        $console = new SqlConsoleController();

        $html = $console->execute();

        $this->assertStringContainsString('Invalid form submission', $html);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wbtest_fixture.widgets')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_execute_action_runs_the_statement_with_a_valid_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = ['csrf_token' => Csrf::token(), 'sql' => "INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')"];
        $console = new SqlConsoleController();

        $console->execute();

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM wbtest_fixture.widgets')->fetchColumn();
        $this->assertSame(2, $count);
    }
}
