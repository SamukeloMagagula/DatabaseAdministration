<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Log in</title><link rel="stylesheet" href="/assets/style.css"></head>
<body>
<main class="login-page">
<h1>DB Web Admin</h1>
<?php if ($error): ?><p class="error"><?= \App\View::e($error) ?></p><?php endif; ?>
<form method="post" action="/login">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
</form>
</main>
</body>
</html>
