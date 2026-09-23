<h1>Audit Log</h1>
<form method="get" class="filter-form">
    <label>Username <input type="text" name="username" value="<?= e($filters['username'] ?? '') ?>"></label>
    <label>From <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
    <button type="submit">Filter</button>
</form>
<table class="grid">
<thead><tr><th>When</th><th>User</th><th>Action</th><th>DB</th><th>Table</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
    <td><?= e($entry['created_at']) ?></td>
    <td><?= e($entry['username']) ?></td>
    <td><?= e($entry['action_type']) ?></td>
    <td><?= e((string) $entry['target_db']) ?></td>
    <td><?= e((string) $entry['target_table']) ?></td>
    <td><code><?= e($entry['detail']) ?></code></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Page <?= $page ?></p>
