<?php

declare(strict_types=1);

function setup_users_fixture(): void
{
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('app_users'));
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('audit_log'));
}

test('create_app_user hashes the password and logs it', function (): void {
    setup_users_fixture();
    create_app_user(test_pdo(), 'bob', 'secret123', 'editor', 1, 'admin1');

    $table = app_table('app_users');
    $row = test_pdo()->query("SELECT * FROM {$table} WHERE username = 'bob'")->fetch();
    check($row !== false, 'expected a row for bob');
    check(password_verify('secret123', $row['password_hash']), 'password should verify');
    same('editor', $row['role']);

    $entries = audit_recent(test_pdo());
    same('USER_CREATE', $entries[0]['action_type']);
});

test('set_user_role changes the role', function (): void {
    setup_users_fixture();
    create_app_user(test_pdo(), 'bob', 'secret123', 'viewer', 1, 'admin1');
    $table = app_table('app_users');
    $userId = (int) test_pdo()->query("SELECT id FROM {$table} WHERE username = 'bob'")->fetchColumn();

    set_user_role(test_pdo(), $userId, 'admin', 1, 'admin1');

    same('admin', test_pdo()->query("SELECT role FROM {$table} WHERE id = {$userId}")->fetchColumn());
});

test('set_user_active disables a user', function (): void {
    setup_users_fixture();
    create_app_user(test_pdo(), 'bob', 'secret123', 'viewer', 1, 'admin1');
    $table = app_table('app_users');
    $userId = (int) test_pdo()->query("SELECT id FROM {$table} WHERE username = 'bob'")->fetchColumn();

    set_user_active(test_pdo(), $userId, false, 1, 'admin1');

    same(0, (int) test_pdo()->query("SELECT is_active FROM {$table} WHERE id = {$userId}")->fetchColumn());
});

test('all_app_users lists everyone', function (): void {
    setup_users_fixture();
    create_app_user(test_pdo(), 'bob', 'secret123', 'viewer', 1, 'admin1');

    $usernames = array_column(all_app_users(test_pdo()), 'username');

    check(in_array('bob', $usernames, true), 'bob should be listed');
});
