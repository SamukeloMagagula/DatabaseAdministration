<?php

declare(strict_types=1);

/**
 * Create the first admin account.
 *
 *   php cli/bootstrap_admin.php
 *
 * There is no UI to create the very first account — every other one is made
 * from the Manage Users page by an admin who already exists. This is how that
 * first admin gets there.
 */

require_once __DIR__ . '/../settings.php';

if (!is_readable(CONFIG_PATH)) {
    fwrite(STDERR, "Cannot read the database config at " . CONFIG_PATH . "\n");
    exit(1);
}
require CONFIG_PATH;

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

$pdo = connect();
$table = app_table('app_users');

$exists = $pdo->prepare("SELECT 1 FROM {$table} WHERE username = :u");
$exists->execute(['u' => $username]);
if ($exists->fetchColumn()) {
    fwrite(STDERR, "User '{$username}' already exists.\n");
    exit(1);
}

$insert = $pdo->prepare("INSERT INTO {$table} (username, password_hash, role, is_active) VALUES (:u, :p, 'admin', 1)");
try {
    $insert->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    // A race with a concurrent bootstrap attempt lands here instead of the
    // pre-check above; same outcome either way.
    fwrite(STDERR, "User '{$username}' already exists.\n");
    exit(1);
}

echo "Admin user '{$username}' created.\n";
