<?php

declare(strict_types=1);

// csrf.php reads and writes $_SESSION, which does not exist under the CLI
// SAPI. A plain array behaves identically for the functions under test.
$_SESSION = [];

test('token is stable across calls in the same session', function (): void {
    $_SESSION = [];
    $first = csrf_token();
    $second = csrf_token();

    same($first, $second);
    check($first !== '', 'token should not be empty');
});

test('validate accepts the current token', function (): void {
    $_SESSION = [];
    $token = csrf_token();

    same(true, csrf_valid($token));
});

test('validate rejects a wrong token', function (): void {
    $_SESSION = [];
    csrf_token();

    same(false, csrf_valid('not-the-token'));
});

test('validate rejects null', function (): void {
    $_SESSION = [];
    csrf_token();

    same(false, csrf_valid(null));
});

test('validate rejects when no token was ever issued', function (): void {
    $_SESSION = [];

    same(false, csrf_valid('anything'));
});
