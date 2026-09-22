<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Controllers\AuthController;
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

$router->add('GET', '/login', fn($p) => $auth->showLogin());
$router->add('POST', '/login', fn($p) => $auth->handleLogin());
$router->add('POST', '/logout', fn($p) => $auth->handleLogout());
$router->add('GET', '/', fn($p) => \App\Auth::requireLogin() ?? 'Logged in.');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$match = $router->match($_SERVER['REQUEST_METHOD'], $path);

if ($match === null) {
    http_response_code(404);
    echo View::render('error_404', [], null);
    exit;
}

echo ($match['handler'])($match['params']);
