<h1>Manage Users</h1>
<table class="grid">
<thead><tr><th>Username</th><th>Role</th><th>Active</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= \App\View::e($u['username']) ?></td>
    <td>
        <form method="post" action="/users/<?= (int) $u['id'] ?>/role" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
            <select name="role" onchange="this.form.submit()">
            <?php foreach ($roles as $r): ?>
                <option value="<?= \App\View::e($r) ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= \App\View::e($r) ?></option>
            <?php endforeach; ?>
            </select>
        </form>
    </td>
    <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
    <td>
        <form method="post" action="/users/<?= (int) $u['id'] ?>/active" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
            <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
            <button type="submit"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Add User</h2>
<form method="post" action="/users">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required></label>
    <label>Role
        <select name="role">
        <?php foreach ($roles as $r): ?><option value="<?= \App\View::e($r) ?>"><?= \App\View::e($r) ?></option><?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Create</button>
</form>
