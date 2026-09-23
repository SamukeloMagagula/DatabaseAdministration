<?php

declare(strict_types=1);

require_once __DIR__ . '/view.php';

// Required before CONFIG_PATH or the database, so a failed connection
// (whose exception carries the DB password in its stack frame) is logged
// rather than rendered to the browser.
function install_error_handler(): void
{
    set_exception_handler(function (Throwable $e): void {
        error_log((string) $e);

        if ($e instanceof InvalidArgumentException) {
            http_response_code(404);
            echo render('error_404', [], null);
            return;
        }

        http_response_code(500);
        echo render('error_500', [], null);
    });
}
