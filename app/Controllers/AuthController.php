<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Http;
use App\RateLimiter;
use App\View;

final class AuthController
{
    private Auth $auth;
    private AuditLog $auditLog;

    public function __construct()
    {
        $pdo = Database::connection();
        $this->auth = new Auth($pdo, new RateLimiter($pdo));
        $this->auditLog = new AuditLog($pdo);
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

        $username = (string) ($_POST['username'] ?? '');
        $result = $this->auth->attemptLogin(
            $username,
            (string) ($_POST['password'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );

        // Never record the submitted password, only the attempted username —
        // clamped to the first 64 valid UTF-8 characters so that an oversized
        // or malformed submission cannot fail the audit_log.username insert.
        // (preg_replace returns null on invalid UTF-8, which casts to ''.)
        $attempted = (string) preg_replace('/^(.{0,64}).*$/us', '$1', $username);
        $detail = "login attempt for '{$attempted}'";

        if (!$result['success']) {
            $lockedOut = $result['error'] === 'locked_out';
            $this->auditLog->record(
                null,
                $attempted,
                $lockedOut ? 'LOGIN_LOCKOUT' : 'LOGIN_FAIL',
                null,
                null,
                $detail
            );
            $message = $lockedOut
                ? 'Too many failed attempts. Try again in a few minutes.'
                : 'Invalid username or password.';
            return View::render('login', ['csrfToken' => Csrf::token(), 'error' => $message], null);
        }

        $user = Auth::currentUser();
        $this->auditLog->record($user['id'], $user['username'], 'LOGIN_OK', null, null, $detail);

        return Http::redirect('/');
    }

    public function handleLogout(): string
    {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        Auth::logout();
        return Http::redirect('/login');
    }
}
