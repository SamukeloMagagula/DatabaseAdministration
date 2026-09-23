<?php

declare(strict_types=1);

test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));

test('record and recent round trip, most recent first', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));

    audit_record(test_pdo(), 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
    audit_record(test_pdo(), 'bob', 'ROW_DELETE', 'shop', 'orders', '{"pk":5}');

    $entries = audit_recent(test_pdo());

    check(count($entries) === 2, 'expected 2 entries');
    same('bob', $entries[0]['username']);
    same('alice', $entries[1]['username']);
});

test('recent filters by username', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
    audit_record(test_pdo(), 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
    audit_record(test_pdo(), 'bob', 'SQL_EXEC', null, null, 'SELECT 2');

    $entries = audit_recent(test_pdo(), 50, 0, 'bob');

    check(count($entries) === 1, 'expected 1 entry');
    same('bob', $entries[0]['username']);
});

test('recent respects limit and offset', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
    for ($i = 0; $i < 3; $i++) {
        audit_record(test_pdo(), 'alice', 'SQL_EXEC', null, null, "SELECT {$i}");
    }

    $page = audit_recent(test_pdo(), 1, 1);

    check(count($page) === 1, 'expected 1 entry');
});
