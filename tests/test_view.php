<?php

declare(strict_types=1);

test('render injects data into a template with no layout', function (): void {
    set_views_path(__DIR__ . '/fixtures/views');

    $html = render('hello', ['name' => 'World'], null);

    same('Hello, World!', trim($html));

    set_views_path(__DIR__ . '/../views');
});

test('e escapes HTML special characters', function (): void {
    same('&lt;script&gt;', e('<script>'));
});

test('e treats null as empty', function (): void {
    same('', e(null));
});
