<?php

declare(strict_types=1);

// The shared layout (views/layout.php) reads ROLE_ADMIN and calls
// csrf_token() for the nav's role-gated links and logout form, so anything
// that can render() must have both available — not left to every page to
// remember on its own.
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/csrf.php';

/** Render one of the templates in views/, wrapped in the shared layout unless $layout is null. */
function render(string $view, array $data = [], ?string $layout = 'layout'): string
{
    $content = render_template($view, $data);
    if ($layout === null) return $content;
    return render_template($layout, $data + ['content' => $content]);
}

/** HTML-escape a value for output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function render_template(string $view, array $data): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require views_path() . "/{$view}.php";
    return (string) ob_get_clean();
}

function views_path(): string
{
    return __DIR__ . '/views';
}
