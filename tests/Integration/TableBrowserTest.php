<?php

namespace Tests\Integration;

use App\Controllers\DashboardController;
use App\Controllers\TableController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableBrowserTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec(
            'CREATE TABLE wbtest_fixture.widgets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 0
            )'
        );
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
    }

    public function test_list_tables_returns_known_table(): void
    {
        $controller = new TableController();

        $this->assertSame(['widgets'], $controller->listTables('wbtest_fixture'));
    }

    public function test_list_tables_rejects_unknown_database(): void
    {
        $controller = new TableController();

        $this->expectException(\InvalidArgumentException::class);
        $controller->listTables('no_such_database');
    }

    public function test_columns_returns_column_metadata_in_order(): void
    {
        $controller = new TableController();

        $columns = $controller->columns('wbtest_fixture', 'widgets');

        $this->assertSame(['id', 'name', 'quantity'], array_column($columns, 'COLUMN_NAME'));
    }

    public function test_primary_key_column_is_detected(): void
    {
        $controller = new TableController();

        $this->assertSame('id', $controller->primaryKeyColumn('wbtest_fixture', 'widgets'));
    }

    public function test_dashboard_excludes_system_and_app_schemas(): void
    {
        $controller = new DashboardController();

        $html = $controller->index();

        $this->assertStringNotContainsString('>mysql<', $html);
        $this->assertStringNotContainsString('>information_schema<', $html);
        // Asserted against the actually-configured schema, not a literal, so
        // deleting the exclusion cannot leave this test passing.
        $appSchema = \App\Config::get('DB_APP_SCHEMA', 'dbwebui_app');
        $this->assertStringNotContainsString('>' . $appSchema . '<', $html);
        $this->assertStringContainsString('wbtest_fixture', $html);
    }

    public function test_the_app_schema_is_not_browsable_through_the_grid(): void
    {
        $controller = new TableController();

        $this->expectException(\InvalidArgumentException::class);
        $controller->listTables(\App\Config::get('DB_APP_SCHEMA', 'dbwebui_app'));
    }

    public function test_the_app_schema_tables_cannot_be_read_through_the_grid(): void
    {
        $controller = new TableController();

        $this->expectException(\InvalidArgumentException::class);
        $controller->columns(\App\Config::get('DB_APP_SCHEMA', 'dbwebui_app'), 'app_users');
    }

    public function test_list_for_database_renders_table_names(): void
    {
        $controller = new TableController();

        $html = $controller->listForDatabase('wbtest_fixture');

        $this->assertStringContainsString('widgets', $html);
    }

    public function test_structure_renders_column_names(): void
    {
        $controller = new TableController();

        $html = $controller->structure('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('quantity', $html);
    }

    public function test_browser_actions_redirect_when_logged_out(): void
    {
        $_SESSION = [];
        $controller = new TableController();

        $this->assertSame('', $controller->listForDatabase('wbtest_fixture'));
    }
}
