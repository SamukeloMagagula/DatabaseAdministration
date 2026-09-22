<?php

namespace App;

final class View
{
    private static ?string $viewsPath = null;

    public static function setViewsPath(string $path): void
    {
        self::$viewsPath = $path;
    }

    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::renderTemplate($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderTemplate($layout, $data + ['content' => $content]);
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function renderTemplate(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require self::viewsPath() . "/{$view}.php";
        return (string) ob_get_clean();
    }

    private static function viewsPath(): string
    {
        return self::$viewsPath ?? dirname(__DIR__) . '/resources/views';
    }
}
