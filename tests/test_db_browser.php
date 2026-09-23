<?php

declare(strict_types=1);

/** A throwaway database with one table, for tests that need something real to browse. */
function setup_fixture_db(): void
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
}

function teardown_fixture_db(): void
{
    test_pdo()->exec('DROP DATABASE IF EXISTS wbtest_fixture');
}

test('list_tables returns the known table', function (): void {
    setup_fixture_db();
    same(['widgets'], list_tables(test_pdo(), 'wbtest_fixture'));
    teardown_fixture_db();
});

test('list_tables rejects an unknown database', function (): void {
    try {
        list_tables(test_pdo(), 'no_such_database');
        check(false, 'expected InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
        // expected
    }
});

test('table_columns returns column metadata in order', function (): void {
    setup_fixture_db();
    $columns = table_columns(test_pdo(), 'wbtest_fixture', 'widgets');
    same(['id', 'name', 'quantity'], array_column($columns, 'COLUMN_NAME'));
    teardown_fixture_db();
});

test('primary_key_column is detected', function (): void {
    setup_fixture_db();
    same('id', primary_key_column(test_pdo(), 'wbtest_fixture', 'widgets'));
    teardown_fixture_db();
});

test('list_databases excludes system schemas and this app\'s own schema', function (): void {
    setup_fixture_db();
    $databases = list_databases(test_pdo());

    check(!in_array('mysql', $databases, true), 'mysql should be excluded');
    check(!in_array('information_schema', $databases, true), 'information_schema should be excluded');
    check(!in_array(DB_APP_SCHEMA, $databases, true), 'this app\'s own schema should be excluded');
    check(in_array('wbtest_fixture', $databases, true), 'the fixture database should be listed');
    teardown_fixture_db();
});

test('this app\'s own schema is not browsable through the grid, even by name', function (): void {
    try {
        list_tables(test_pdo(), DB_APP_SCHEMA);
        check(false, 'expected InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
        // expected — this is the check that closed the privilege-escalation
        // path where an editor could reach app_users through the grid.
    }
});

test('this app\'s own schema is rejected case-insensitively', function (): void {
    // information_schema matches case-insensitively, so the guard must too —
    // a byte-exact comparison here would let an uppercased name slip through.
    try {
        list_tables(test_pdo(), strtoupper(DB_APP_SCHEMA));
        check(false, 'expected InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
        // expected
    }
});
