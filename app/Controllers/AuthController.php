<?php

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Http;
use App\RateLimiter;
use App\View;

final class AuthController
{
    private Auth $auth;

    public function __construct()
    {
        $pdo = Database::connection();
        $this->auth = new Auth($pdo, new RateLimiter($pdo));
    }

    public function showLogin(): string
    {
        if (Auth::currentUser() !== null) {
            return Http::redirect('/');
        }
        return View::render('login', ['csrfToken' => Csrf::token(), 'error' => null], null);
    }

    public function handleLogin(): string
    {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return View::render('login', [
                'csrfToken' => Csrf::token(),
                'error' => 'Invalid form submission, please try again.',
            ], null);
        }

        $result = $this->auth->attemptLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!$result['success']) {
            $message = $result['error'] === 'locked_out'
                ? 'Too many failed attempts. Try again in a few minutes.'
                : 'Invalid username or password.';
            return View::render('login', ['csrfToken' => Csrf::token(), 'error' => $message], null);
        }

        return Http::redirect('/');
    }

    public function handleLogout(): string
    {
        Auth::logout();
        return Http::redirect('/login');
    }
}
