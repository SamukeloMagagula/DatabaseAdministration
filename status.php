<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db_browser.php';

require_login();
load_app_config();

echo render('status', [
    'user' => current_user(),
    'status' => server_status(connect()),
]);
