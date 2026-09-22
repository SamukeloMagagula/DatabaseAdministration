<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\SqlConsoleController;
use App\Controllers\TableController;
use App\Controllers\UserController;
use App\Router;
use App\View;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => true,
]);

Config::load(__DIR__ . '/../.env');

$router = new Router();
$auth = new AuthController();
$dashboard = new DashboardController();
$tables = new TableController();
$sqlConsole = new SqlConsoleController();
$audit = new AuditController();
$users = new UserController();

$router->add('GET', '/login', fn($p) => $auth->showLogin());
$router->add('POST', '/login', fn($p) => $auth->handleLogin());
$router->add('POST', '/logout', fn($p) => $auth->handleLogout());
$router->add('GET', '/', fn($p) => $dashboard->index());
$router->add('GET', '/db/{db}/tables', fn($p) => $tables->listForDatabase($p['db']));
$router->add('GET', '/db/{db}/table/{table}/structure', fn($p) => $tables->structure($p['db'], $p['table']));
$router->add('GET', '/db/{db}/table/{table}', fn($p) => $tables->data($p['db'], $p['table']));
$router->add('GET', '/db/{db}/table/{table}/new', fn($p) => $tables->newRowForm($p['db'], $p['table']));
$router->add('POST', '/db/{db}/table/{table}/rows', fn($p) => $tables->createRow($p['db'], $p['table']));
$router->add('GET', '/db/{db}/table/{table}/row/{pk}/edit', fn($p) => $tables->editRowForm($p['db'], $p['table'], $p['pk']));
$router->add('POST', '/db/{db}/table/{table}/row/{pk}', fn($p) => $tables->updateRow($p['db'], $p['table'], $p['pk']));
$router->add('POST', '/db/{db}/table/{table}/row/{pk}/delete', fn($p) => $tables->deleteRow($p['db'], $p['table'], $p['pk']));
$router->add('GET', '/sql', fn($p) => $sqlConsole->show());
$router->add('POST', '/sql', fn($p) => $sqlConsole->execute());
$router->add('GET', '/audit', fn($p) => $audit->index());
$router->add('GET', '/users', fn($p) => $users->index());
$router->add('POST', '/users', fn($p) => $users->create());
$router->add('POST', '/users/{id}/role', fn($p) => $users->updateRole((int) $p['id']));
$router->add('POST', '/users/{id}/active', fn($p) => $users->setActive((int) $p['id']));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$match = $router->match($_SERVER['REQUEST_METHOD'], $path);

if ($match === null) {
    http_response_code(404);
    echo View::render('error_404', [], null);
    exit;
}

echo ($match['handler'])($match['params']);
