<?php

declare(strict_types=1);

// Required before CONFIG_PATH or the database, so a failed connection
// (whose exception carries the DB password in its stack frame) is logged
// rather than rendered to the browser. The error pages are plain static
// HTML, not templates, since neither one has anything dynamic to show.
function install_error_handler(): void
{
    set_exception_handler(function (Throwable $e): void {
        error_log((string) $e);

        header('Content-Type: text/html; charset=utf-8');
        if ($e instanceof InvalidArgumentException) {
            http_response_code(404);
            readfile(__DIR__ . '/assets/errors/404.html');
            return;
        }

        http_response_code(500);
        readfile(__DIR__ . '/assets/errors/500.html');
    });
}
