<h1><?= e($db) ?>.<?= e($table) ?> — structure</h1>
<table class="grid">
<thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
<tbody>
<?php foreach ($columns as $col): ?>
<tr>
    <td><?= e($col['COLUMN_NAME']) ?></td>
    <td><?= e($col['DATA_TYPE']) ?></td>
    <td><?= e($col['IS_NULLABLE']) ?></td>
    <td><?= e($col['COLUMN_KEY']) ?></td>
    <td><?= e((string) $col['COLUMN_DEFAULT']) ?></td>
    <td><?= e($col['EXTRA']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><a href="/table.php?db=<?= e(rawurlencode($db)) ?>&table=<?= e(rawurlencode($table)) ?>">View data</a></p>
