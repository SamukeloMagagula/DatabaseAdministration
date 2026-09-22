<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\MigrationRunner;

$envFile = $argv[1] ?? __DIR__ . '/../.env';
Config::load($envFile);

$runner = new MigrationRunner(Database::connection(), __DIR__ . '/../migrations');
$applied = $runner->run();

if (!$applied) {
    echo "No new migrations to apply.\n";
} else {
    foreach ($applied as $file) {
        echo "Applied: {$file}\n";
    }
}
