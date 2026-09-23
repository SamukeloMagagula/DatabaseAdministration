<?php

declare(strict_types=1);

function setup_grid_fixture(): void
{
    test_pdo()->exec('DROP DATABASE IF EXISTS wbtest_fixture');
    test_pdo()->exec('CREATE DATABASE wbtest_fixture');
    test_pdo()->exec(
        'CREATE TABLE wbtest_fixture.widgets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 0
        )'
    );
    test_pdo()->exec(
        "INSERT INTO wbtest_fixture.widgets (name, quantity) VALUES
         ('bolt', 10), ('nail', 5), ('screw', 20)"
    );
}

function teardown_grid_fixture(): void
{
    test_pdo()->exec('DROP DATABASE IF EXISTS wbtest_fixture');
}

test('list_rows returns all rows and the total', function (): void {
    setup_grid_fixture();
    $result = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 1, 50, null, 'ASC', []);
    same(3, $result['total']);
    check(count($result['rows']) === 3, 'expected 3 rows');
    teardown_grid_fixture();
});

test('list_rows sorts ascending and descending', function (): void {
    setup_grid_fixture();
    $asc = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 1, 50, 'quantity', 'ASC', []);
    same(['nail', 'bolt', 'screw'], array_column($asc['rows'], 'name'));

    $desc = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 1, 50, 'quantity', 'DESC', []);
    same(['screw', 'bolt', 'nail'], array_column($desc['rows'], 'name'));
    teardown_grid_fixture();
});

test('list_rows filters by column value', function (): void {
    setup_grid_fixture();
    $result = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 1, 50, null, 'ASC', ['name' => 'ol']);
    same(['bolt'], array_column($result['rows'], 'name'));
    teardown_grid_fixture();
});

test('list_rows ignores an unknown sort column instead of erroring', function (): void {
    setup_grid_fixture();
    $result = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 1, 50, 'not_a_column', 'ASC', []);
    check(count($result['rows']) === 3, 'expected 3 rows');
    teardown_grid_fixture();
});

test('list_rows paginates', function (): void {
    setup_grid_fixture();
    $result = list_rows(test_pdo(), 'wbtest_fixture', 'widgets', 2, 2, 'id', 'ASC', []);
    check(count($result['rows']) === 1, 'expected 1 row on page 2');
    same(3, $result['total']);
    same(2, $result['page']);
    teardown_grid_fixture();
});

test('filters work on columns whose names are not valid placeholder tokens', function (): void {
    setup_grid_fixture();
    test_pdo()->exec(
        'CREATE TABLE wbtest_fixture.odd (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order-date` VARCHAR(20) NOT NULL,
            `first name` VARCHAR(20) NOT NULL
        )'
    );
    test_pdo()->exec(
        "INSERT INTO wbtest_fixture.odd (`order-date`, `first name`) VALUES
         ('2026-01-01', 'ada'), ('2026-02-02', 'bob')"
    );

    $result = list_rows(test_pdo(), 'wbtest_fixture', 'odd', 1, 50, null, 'ASC', ['first name' => 'ad']);

    same(1, $result['total']);
    same(['ada'], array_column($result['rows'], 'first name'));
    teardown_grid_fixture();
});

test('insert_row writes the row and an audit entry', function (): void {
    setup_grid_fixture();
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
    insert_row(test_pdo(), 'wbtest_fixture', 'widgets', ['name' => 'nail', 'quantity' => '5'], 1, 'alice');

    $row = find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 2);
    same('nail', $row['name']);

    $entries = audit_recent(test_pdo());
    same('ROW_INSERT', $entries[0]['action_type']);
    teardown_grid_fixture();
});

test('insert_row ignores unknown columns instead of erroring', function (): void {
    setup_grid_fixture();
    insert_row(test_pdo(), 'wbtest_fixture', 'widgets', ['name' => 'nail', 'not_a_column' => 'x'], 1, 'alice');

    $row = find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 2);
    same('nail', $row['name']);
    teardown_grid_fixture();
});

test('update_row changes fields and logs', function (): void {
    setup_grid_fixture();
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
    update_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1, ['quantity' => '99'], 1, 'alice');

    $row = find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1);
    same('99', (string) $row['quantity']);

    $entries = audit_recent(test_pdo());
    same('ROW_UPDATE', $entries[0]['action_type']);
    teardown_grid_fixture();
});

test('update_row cannot change the primary key', function (): void {
    setup_grid_fixture();
    update_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1, ['id' => '999', 'quantity' => '1'], 1, 'alice');

    check(find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 999) === null, 'id 999 should not exist');
    check(find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1) !== null, 'id 1 should still exist');
    teardown_grid_fixture();
});

test('delete_row removes the row and logs', function (): void {
    setup_grid_fixture();
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
    delete_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1, 1, 'alice');

    check(find_row(test_pdo(), 'wbtest_fixture', 'widgets', 'id', 1) === null, 'row should be gone');

    $entries = audit_recent(test_pdo());
    same('ROW_DELETE', $entries[0]['action_type']);
    teardown_grid_fixture();
});

test('insert and update both work on columns whose names are not valid placeholder tokens', function (): void {
    setup_grid_fixture();
    test_pdo()->exec(
        'CREATE TABLE wbtest_fixture.odd (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order-date` VARCHAR(20) NOT NULL,
            `first name` VARCHAR(20) NOT NULL
        )'
    );

    insert_row(test_pdo(), 'wbtest_fixture', 'odd', ['order-date' => '2026-01-01', 'first name' => 'ada'], 1, 'alice');
    same('ada', find_row(test_pdo(), 'wbtest_fixture', 'odd', 'id', 1)['first name']);

    update_row(test_pdo(), 'wbtest_fixture', 'odd', 'id', 1, ['first name' => 'bob'], 1, 'alice');
    same('bob', find_row(test_pdo(), 'wbtest_fixture', 'odd', 'id', 1)['first name']);
    teardown_grid_fixture();
});
