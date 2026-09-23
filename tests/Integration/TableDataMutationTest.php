<?php

namespace Tests\Integration;

use App\Controllers\TableController;
use App\AuditLog;
use App\Csrf;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableDataMutationTest extends TestCase
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
        $this->pdo->exec("INSERT INTO wbtest_fixture.widgets (name, quantity) VALUES ('bolt', 10)");
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

    public function test_insert_row_writes_row_and_audit_entry(): void
    {
        $controller = new TableController();

        $controller->insertRow('wbtest_fixture', 'widgets', ['name' => 'nail', 'quantity' => '5'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 2);
        $this->assertSame('nail', $row['name']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_INSERT', $entries[0]['action_type']);
    }

    public function test_insert_row_ignores_unknown_columns(): void
    {
        $controller = new TableController();

        $controller->insertRow('wbtest_fixture', 'widgets', ['name' => 'nail', 'not_a_column' => 'x'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 2);
        $this->assertSame('nail', $row['name']);
    }

    public function test_update_row_data_changes_fields_and_logs(): void
    {
        $controller = new TableController();

        $controller->updateRowData('wbtest_fixture', 'widgets', 'id', 1, ['quantity' => '99'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 1);
        $this->assertSame('99', (string) $row['quantity']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_UPDATE', $entries[0]['action_type']);
    }

    public function test_update_row_data_cannot_change_the_primary_key(): void
    {
        $controller = new TableController();

        $controller->updateRowData('wbtest_fixture', 'widgets', 'id', 1, ['id' => '999', 'quantity' => '1'], 1, 'alice');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 999));
        $this->assertNotNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));
    }

    public function test_delete_row_data_removes_row_and_logs(): void
    {
        $controller = new TableController();

        $controller->deleteRowData('wbtest_fixture', 'widgets', 'id', 1, 1, 'alice');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_DELETE', $entries[0]['action_type']);
    }

    public function test_insert_and_update_work_on_columns_whose_names_are_not_valid_placeholders(): void
    {
        $this->pdo->exec(
            'CREATE TABLE wbtest_fixture.odd (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `order-date` VARCHAR(20) NOT NULL,
                `first name` VARCHAR(20) NOT NULL
            )'
        );
        $controller = new TableController();

        $controller->insertRow('wbtest_fixture', 'odd', ['order-date' => '2026-01-01', 'first name' => 'ada'], 1, 'alice');
        $this->assertSame('ada', $controller->findRow('wbtest_fixture', 'odd', 'id', 1)['first name']);

        $controller->updateRowData('wbtest_fixture', 'odd', 'id', 1, ['first name' => 'bob'], 1, 'alice');
        $this->assertSame('bob', $controller->findRow('wbtest_fixture', 'odd', 'id', 1)['first name']);
    }

    public function test_create_row_action_requires_editor_or_admin_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'vic', 'role' => 'viewer'];
        $_POST = ['csrf_token' => Csrf::token(), 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_create_row_action_rejects_bad_csrf_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'ed', 'role' => 'editor'];
        Csrf::token();
        $_POST = ['csrf_token' => 'wrong', 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_create_row_action_succeeds_for_editor_with_valid_csrf(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'ed', 'role' => 'editor'];
        $_POST = ['csrf_token' => Csrf::token(), 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNotNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_delete_row_action_succeeds_for_admin(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = ['csrf_token' => Csrf::token()];
        $controller = new TableController();

        $controller->deleteRow('wbtest_fixture', 'widgets', '1');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));
    }
}
