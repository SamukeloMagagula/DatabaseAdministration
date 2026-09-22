<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;

$envFile = __DIR__ . '/../.env.testing';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing .env.testing — copy .env.testing.example and point it at a disposable test schema.\n");
    exit(1);
}
Config::load($envFile);
