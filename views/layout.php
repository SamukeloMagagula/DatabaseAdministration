<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Database Administration</title>
<link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
<script src="<?= e(url('/assets/app.js')) ?>" defer></script>
</head>
<body>
<nav class="topnav">
    <a href="<?= e(url('/index.php')) ?>">Databases</a>
    <a href="<?= e(url('/sql.php')) ?>">SQL Console</a>
    <a href="<?= e(url('/status.php')) ?>">Status</a>
    <?php if (($user['role'] ?? null) === ROLE_ADMIN): ?>
        <a href="<?= e(url('/audit_log.php')) ?>">Audit Log</a>
    <?php endif; ?>
    <span class="topnav-user">
        <?= e($user['username'] ?? '') ?> (<?= e($user['role'] ?? '') ?>)
        <form method="post" action="<?= e(url('/auth/auth.php')) ?>" class="inline-form">
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
