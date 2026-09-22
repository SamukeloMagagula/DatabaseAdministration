<?php

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Database;
use App\View;
use PDO;

final class DashboardController
{
    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    public function index(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }

        $appSchema = Config::get('DB_APP_SCHEMA', 'dbwebui_app');
        $all = Database::connection()->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $databases = array_values(array_diff($all, self::SYSTEM_SCHEMAS, [$appSchema]));

        return View::render('dashboard', [
            'user' => Auth::currentUser(),
            'databases' => $databases,
        ]);
    }
}
