<?php

declare(strict_types=1);

function setup_console_fixture(): void
{
    test_pdo()->exec('DROP DATABASE IF EXISTS wbtest_fixture');
    test_pdo()->exec('CREATE DATABASE wbtest_fixture');
    test_pdo()->exec('CREATE TABLE wbtest_fixture.widgets (id INT PRIMARY KEY, name VARCHAR(50))');
    test_pdo()->exec("INSERT INTO wbtest_fixture.widgets VALUES (1, 'bolt')");
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
}

function teardown_console_fixture(): void
{
    test_pdo()->exec('DROP DATABASE IF EXISTS wbtest_fixture');
}

test('viewer can run SELECT', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), 'SELECT * FROM wbtest_fixture.widgets', ROLE_VIEWER, 'vic');
    same(true, $result['ok']);
    same(1, $result['rowCount']);
    teardown_console_fixture();
});

test('viewer cannot run INSERT', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), "INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", ROLE_VIEWER, 'vic');
    same(false, $result['ok']);
    check(str_contains($result['error'], 'INSERT'), 'error should mention INSERT');
    teardown_console_fixture();
});

test('editor can run INSERT but not DDL', function (): void {
    setup_console_fixture();
    $insert = run_sql_statement(test_pdo(), "INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", ROLE_EDITOR, 'ed');
    same(true, $insert['ok']);

    $ddl = run_sql_statement(test_pdo(), 'DROP TABLE wbtest_fixture.widgets', ROLE_EDITOR, 'ed');
    same(false, $ddl['ok']);
    teardown_console_fixture();
});

test('admin can run DDL', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), 'ALTER TABLE wbtest_fixture.widgets ADD COLUMN qty INT', ROLE_ADMIN, 'admin1');
    same(true, $result['ok']);
    teardown_console_fixture();
});

test('every execution is audited, including a rejection', function (): void {
    setup_console_fixture();
    run_sql_statement(test_pdo(), 'SELECT * FROM wbtest_fixture.widgets', ROLE_VIEWER, 'vic');
    run_sql_statement(test_pdo(), 'DELETE FROM wbtest_fixture.widgets', ROLE_VIEWER, 'vic');

    $entries = audit_recent(test_pdo());
    check(count($entries) === 2, 'expected 2 audit entries');
    same('SQL_REJECTED', $entries[0]['action_type']);
    same('SQL_EXEC', $entries[1]['action_type']);
    teardown_console_fixture();
});

test('a SQL error is caught and audited rather than crashing', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), 'SELECT * FROM wbtest_fixture.no_such_table', ROLE_ADMIN, 'admin1');
    same(false, $result['ok']);
    $entries = audit_recent(test_pdo());
    same('SQL_ERROR', $entries[0]['action_type']);
    teardown_console_fixture();
});

test('an empty statement is rejected without touching the database', function (): void {
    $result = run_sql_statement(test_pdo(), '', ROLE_ADMIN, 'admin1');
    same(false, $result['ok']);
});

test('a viewer cannot bypass the role gate with an unrecognised statement', function (): void {
    setup_console_fixture();
    // REPLACE INTO is a real mutation classify_sql() has no keyword for — the
    // exact bug role_can_run_statement()'s OTHER-requires-admin rule closed.
    $result = run_sql_statement(test_pdo(), "REPLACE INTO wbtest_fixture.widgets VALUES (99, 'pwned')", ROLE_VIEWER, 'vic');
    same(false, $result['ok']);
    $smuggled = (int) test_pdo()->query('SELECT COUNT(*) FROM wbtest_fixture.widgets WHERE id = 99')->fetchColumn();
    same(0, $smuggled);
    teardown_console_fixture();
});

test('a stacked statement does not execute its second half', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), 'SELECT 1; DROP TABLE wbtest_fixture.widgets;', ROLE_ADMIN, 'admin1');
    same(false, $result['ok']);
    $stillThere = (int) test_pdo()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = 'wbtest_fixture' AND TABLE_NAME = 'widgets'"
    )->fetchColumn();
    same(1, $stillThere);
    teardown_console_fixture();
});

test('a non-admin cannot name this app\'s own schema', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(
        test_pdo(),
        "UPDATE `" . DB_APP_SCHEMA . "`.app_users SET role = 'admin' WHERE username = 'ed'",
        ROLE_EDITOR,
        'ed'
    );
    same(false, $result['ok']);
    check(str_contains($result['error'], 'restricted schema'), 'error should mention the restricted schema');
    $entries = audit_recent(test_pdo());
    same('SQL_REJECTED', $entries[0]['action_type']);
    teardown_console_fixture();
});

test('a non-admin cannot hide the app schema behind a block comment or a line comment', function (): void {
    setup_console_fixture();
    // MariaDB's lexer treats a comment in this position exactly like
    // whitespace, so both variants still reference the app's own table.
    $block = run_sql_statement(
        test_pdo(),
        'UPDATE ' . DB_APP_SCHEMA . "/**/.app_users SET role = 'admin' WHERE username = 'ed'",
        ROLE_EDITOR,
        'ed'
    );
    same(false, $block['ok']);
    check(str_contains($block['error'], 'restricted schema'), 'block comment variant should be rejected');

    $line = run_sql_statement(
        test_pdo(),
        'UPDATE ' . DB_APP_SCHEMA . " -- x\n.app_users SET role = 'admin' WHERE username = 'ed'",
        ROLE_EDITOR,
        'ed'
    );
    same(false, $line['ok']);
    check(str_contains($line['error'], 'restricted schema'), 'line comment variant should be rejected');
    teardown_console_fixture();
});

test('a non-admin cannot hide the app schema behind a fake line comment', function (): void {
    // `--` only starts a comment in MariaDB when whitespace follows it, so
    // `1--2` is arithmetic. A stripper that truncates the line anyway would
    // delete the real reference that follows, and a block comment defeats the
    // raw-text pass, so both passes had to miss this for it to slip through.
    setup_console_fixture();
    $result = run_sql_statement(
        test_pdo(),
        'SELECT 1--2 AS x, u.* FROM ' . DB_APP_SCHEMA . '/**/.app_users u',
        ROLE_VIEWER,
        'vic'
    );
    same(false, $result['ok']);
    check(str_contains($result['error'], 'restricted schema'), 'expected the schema guard to fire');
    teardown_console_fixture();
});

test('a non-admin cannot hide the app schema behind a string literal', function (): void {
    // The '-- x' is a string literal, not a comment, so the reference that
    // follows it is real.
    setup_console_fixture();
    $result = run_sql_statement(
        test_pdo(),
        "SELECT '-- x' AS note, u.* FROM " . DB_APP_SCHEMA . '/**/.app_users u',
        ROLE_VIEWER,
        'vic'
    );
    same(false, $result['ok']);
    check(str_contains($result['error'], 'restricted schema'), 'expected the schema guard to fire');
    teardown_console_fixture();
});

test('a non-admin cannot reach the app schema through an executable comment', function (): void {
    // MariaDB *executes* the body of a /*! ... */ comment, so dropping it
    // would hide a live reference to the app schema.
    setup_console_fixture();
    $result = run_sql_statement(
        test_pdo(),
        "UPDATE /*!" . DB_APP_SCHEMA . "*/.app_users SET role = 'admin' WHERE username = 'ed'",
        ROLE_EDITOR,
        'ed'
    );
    same(false, $result['ok']);
    check(str_contains($result['error'], 'restricted schema'), 'expected the schema guard to fire');
    teardown_console_fixture();
});

test('a schema reference inside a string literal earlier in the statement still trips the guard', function (): void {
    // A '#' inside a literal is not a comment, so the qualified reference
    // later in the statement is real and must still be rejected.
    setup_console_fixture();
    $result = run_sql_statement(
        test_pdo(),
        "UPDATE wbtest_fixture.widgets SET name = '#' WHERE id = (SELECT id FROM " . DB_APP_SCHEMA . '.app_users LIMIT 1)',
        ROLE_EDITOR,
        'ed'
    );
    same(false, $result['ok']);
    check(str_contains($result['error'], 'restricted schema'), 'expected the schema guard to fire');
    teardown_console_fixture();
});

test('a trailing comment does not block a legitimate statement', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), "SELECT * FROM wbtest_fixture.widgets -- just a note\n", ROLE_VIEWER, 'vic');
    same(true, $result['ok']);
    same(1, $result['rowCount']);
    teardown_console_fixture();
});

test('an unqualified statement cannot reach the app schema (no default database)', function (): void {
    setup_console_fixture();
    $result = run_sql_statement(test_pdo(), "UPDATE app_users SET role = 'admin' WHERE username = 'ed'", ROLE_EDITOR, 'ed');
    same(false, $result['ok']);
    $entries = audit_recent(test_pdo());
    same('SQL_ERROR', $entries[0]['action_type']);
    teardown_console_fixture();
});
