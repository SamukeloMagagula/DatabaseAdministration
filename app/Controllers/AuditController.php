<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Database;
use App\Roles;
use App\View;

final class AuditController
{
    public function index(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }

        $auditLog = new AuditLog(Database::connection());
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $username = $_GET['username'] ?? null;
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;

        $entries = $auditLog->recent($pageSize, ($page - 1) * $pageSize, $username ?: null, $from ?: null, $to ?: null);

        return View::render('audit_log', [
            'user' => Auth::currentUser(),
            'entries' => $entries,
            'page' => $page,
            'filters' => ['username' => $username, 'from' => $from, 'to' => $to],
        ]);
    }
}
