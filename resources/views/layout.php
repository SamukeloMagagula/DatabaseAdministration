<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DB Web Admin</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav">
    <a href="/">Databases</a>
    <a href="/sql">SQL Console</a>
    <?php if (($user['role'] ?? null) === \App\Roles::ADMIN): ?>
        <a href="/users">Users</a>
        <a href="/audit">Audit Log</a>
    <?php endif; ?>
    <span class="topnav-user">
        <?= \App\View::e($user['username'] ?? '') ?> (<?= \App\View::e($user['role'] ?? '') ?>)
        <form method="post" action="/logout" style="display:inline">
            <button type="submit">Logout</button>
        </form>
    </span>
</nav>
<main>
<?= $content ?>
</main>
</body>
</html>
