<?php

namespace App;

final class Http
{
    public static function redirect(string $path): string
    {
        header("Location: {$path}");
        return '';
    }
}
