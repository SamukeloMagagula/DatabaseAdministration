<?php

require __DIR__ . '/../vendor/autoload.php';

use App\AdminBootstrapper;
use App\Config;
use App\Database;

$envFile = $argv[1] ?? __DIR__ . '/../.env';
Config::load($envFile);

echo "Admin username: ";
$username = trim((string) fgets(STDIN));

echo "Admin password: ";
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
echo "\n";

if ($username === '' || $password === '') {
    fwrite(STDERR, "Username and password are required.\n");
    exit(1);
}

$bootstrapper = new AdminBootstrapper(Database::connection());

try {
    $bootstrapper->createFirstAdmin($username, $password);
    echo "Admin user '{$username}' created.\n";
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
