<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Log in</title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body>
<main class="login-page">
<h1>Database Administration</h1>
<p>Use your server login, the same username and password you use over SSH.</p>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="auth/auth.php">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
</form>
</main>
</body>
</html>
