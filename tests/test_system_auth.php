<?php

declare(strict_types=1);

test('resolve_role picks admin over editor when both groups are present', function (): void {
    same(ROLE_ADMIN, resolve_role(['dbwebui-admin', 'dbwebui-editor']));
});

test('resolve_role picks editor when only the editor group is present', function (): void {
    same(ROLE_EDITOR, resolve_role(['wheel', 'dbwebui-editor']));
});

test('resolve_role falls back to viewer for an unrelated group list', function (): void {
    same(ROLE_VIEWER, resolve_role(['wheel', 'users']));
});

test('resolve_role falls back to viewer for no groups at all', function (): void {
    same(ROLE_VIEWER, resolve_role([]));
});
