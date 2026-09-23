<?php

declare(strict_types=1);

test_pdo()->exec('TRUNCATE TABLE ' . app_table('login_attempts'));

test('not locked out before five failures', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('login_attempts'));

    for ($i = 0; $i < 4; $i++) {
        record_login_attempt(test_pdo(), 'alice', false);
    }

    same(false, login_locked_out(test_pdo(), 'alice'));
});

test('locked out after five failures', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('login_attempts'));

    for ($i = 0; $i < 5; $i++) {
        record_login_attempt(test_pdo(), 'alice', false);
    }

    same(true, login_locked_out(test_pdo(), 'alice'));
});

test('lockout is per identifier', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('login_attempts'));

    for ($i = 0; $i < 5; $i++) {
        record_login_attempt(test_pdo(), 'alice', false);
    }

    same(false, login_locked_out(test_pdo(), 'bob'));
});

test('a success resets the consecutive-failure streak', function (): void {
    test_pdo()->exec('TRUNCATE TABLE ' . app_table('login_attempts'));

    for ($i = 0; $i < 4; $i++) {
        record_login_attempt(test_pdo(), 'alice', false);
    }
    record_login_attempt(test_pdo(), 'alice', true);

    // Four more failures: 8 in total, but only 4 since the success, so the
    // 5-consecutive-failure threshold is not reached.
    for ($i = 0; $i < 4; $i++) {
        record_login_attempt(test_pdo(), 'alice', false);
    }

    same(false, login_locked_out(test_pdo(), 'alice'));
});
