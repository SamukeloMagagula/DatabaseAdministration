<?php

namespace Tests\Integration;

use App\Controllers\TableController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableDataListingTest extends TestCase
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
        $this->pdo->exec(
            "INSERT INTO wbtest_fixture.widgets (name, quantity) VALUES
             ('bolt', 10), ('nail', 5), ('screw', 20)"
        );
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'viewer'];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
        $_GET = [];
    }

    public function test_list_rows_returns_all_rows_and_total(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, null, 'ASC', []);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['rows']);
    }

    public function test_list_rows_sorts_by_column(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'quantity', 'ASC', []);

        $this->assertSame(['nail', 'bolt', 'screw'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_sorts_descending(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'quantity', 'DESC', []);

        $this->assertSame(['screw', 'bolt', 'nail'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_filters_by_column_value(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, null, 'ASC', ['name' => 'ol']);

        $this->assertSame(['bolt'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_ignores_unknown_sort_column(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'not_a_column', 'ASC', []);

        $this->assertCount(3, $result['rows']);
    }

    public function test_list_rows_paginates(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 2, 2, 'id', 'ASC', []);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['page']);
    }

    public function test_data_action_renders_rows_for_logged_in_viewer(): void
    {
        $controller = new TableController();

        $html = $controller->data('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('bolt', $html);
        $this->assertStringContainsString('nail', $html);
        $this->assertStringContainsString('screw', $html);
    }

    public function test_data_action_respects_query_string_paging(): void
    {
        $_GET = ['page' => '1', 'page_size' => '1', 'sort' => 'quantity', 'dir' => 'ASC'];
        $controller = new TableController();

        $html = $controller->data('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('nail', $html);
        $this->assertStringNotContainsString('screw', $html);
    }
}
