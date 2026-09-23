<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Database Administration</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav">
    <a href="/index.php">Databases</a>
    <a href="/sql.php">SQL Console</a>
    <a href="/status.php">Status</a>
    <?php if (($user['role'] ?? null) === ROLE_ADMIN): ?>
        <a href="/audit_log.php">Audit Log</a>
    <?php endif; ?>
    <span class="topnav-user">
        <?= e($user['username'] ?? '') ?> (<?= e($user['role'] ?? '') ?>)
        <form method="post" action="/auth/auth.php" style="display:inline">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit">Logout</button>
        </form>
    </span>
</nav>
<main>
<?= $content ?>
</main>
</body>
</html>
