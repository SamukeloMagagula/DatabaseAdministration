<h1>Audit Log</h1>
<form method="get" class="filter-form">
    <label>Username <input type="text" name="username" value="<?= \App\View::e($filters['username'] ?? '') ?>"></label>
    <label>From <input type="date" name="from" value="<?= \App\View::e($filters['from'] ?? '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= \App\View::e($filters['to'] ?? '') ?>"></label>
    <button type="submit">Filter</button>
</form>
<table class="grid">
<thead><tr><th>When</th><th>User</th><th>Action</th><th>DB</th><th>Table</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
    <td><?= \App\View::e($entry['created_at']) ?></td>
    <td><?= \App\View::e($entry['username']) ?></td>
    <td><?= \App\View::e($entry['action_type']) ?></td>
    <td><?= \App\View::e((string) $entry['target_db']) ?></td>
    <td><?= \App\View::e((string) $entry['target_table']) ?></td>
    <td><code><?= \App\View::e($entry['detail']) ?></code></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Page <?= $page ?></p>
