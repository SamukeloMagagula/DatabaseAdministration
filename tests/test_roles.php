<?php

declare(strict_types=1);

test('viewer can only select', function (): void {
    same(true, role_can_run_statement(ROLE_VIEWER, 'SELECT'));
    same(false, role_can_run_statement(ROLE_VIEWER, 'INSERT'));
    same(false, role_can_run_statement(ROLE_VIEWER, 'DDL'));
});

test('editor can run DML but not DDL', function (): void {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $type) {
        same(true, role_can_run_statement(ROLE_EDITOR, $type), $type);
    }
    same(false, role_can_run_statement(ROLE_EDITOR, 'DDL'));
});

test('admin can run anything', function (): void {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'] as $type) {
        same(true, role_can_run_statement(ROLE_ADMIN, $type), $type);
    }
});

test('an unrecognised statement type requires admin', function (): void {
    // This is the rule that closed a real privilege-escalation bug: REPLACE
    // INTO, CALL and LOCK TABLES all classify as OTHER, and OTHER used to be
    // allowed for every role.
    same(false, role_can_run_statement(ROLE_VIEWER, 'OTHER'));
    same(false, role_can_run_statement(ROLE_EDITOR, 'OTHER'));
    same(true, role_can_run_statement(ROLE_ADMIN, 'OTHER'));
});

test('role_is_valid rejects an unknown role', function (): void {
    same(true, role_is_valid('admin'));
    same(false, role_is_valid('superuser'));
});
