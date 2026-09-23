<h1><?= \App\View::e($db) ?>.<?= \App\View::e($table) ?> — structure</h1>
<table class="grid">
<thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
<tbody>
<?php foreach ($columns as $col): ?>
<tr>
    <td><?= \App\View::e($col['COLUMN_NAME']) ?></td>
    <td><?= \App\View::e($col['DATA_TYPE']) ?></td>
    <td><?= \App\View::e($col['IS_NULLABLE']) ?></td>
    <td><?= \App\View::e($col['COLUMN_KEY']) ?></td>
    <td><?= \App\View::e((string) $col['COLUMN_DEFAULT']) ?></td>
    <td><?= \App\View::e($col['EXTRA']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><a href="/db/<?= \App\View::e(rawurlencode($db)) ?>/table/<?= \App\View::e(rawurlencode($table)) ?>">View data</a></p>
