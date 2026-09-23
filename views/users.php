<h1>Manage Users</h1>
<table class="grid">
<thead><tr><th>Username</th><th>Role</th><th>Active</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= e($u['username']) ?></td>
    <td>
        <form method="post" action="/manage_users.php" style="display:inline">
            <input type="hidden" name="action" value="set_role">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <select name="role" onchange="this.form.submit()">
            <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
            </select>
        </form>
    </td>
    <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
    <td>
        <form method="post" action="/manage_users.php" style="display:inline">
            <input type="hidden" name="action" value="set_active">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
            <button type="submit"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Add User</h2>
<form method="post" action="/manage_users.php">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required></label>
    <label>Role
        <select name="role">
        <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Create</button>
</form>
