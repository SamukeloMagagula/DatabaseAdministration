<?php $dbSeg = rawurlencode($db); $tableSeg = rawurlencode($table); ?>
<h1><?= e($db) ?>.<?= e($table) ?></h1>
<p>
    <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=structure">View structure</a>
    | <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=export_csv">Export CSV</a>
    | <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=print" target="_blank">Print / Save as PDF</a>
    <?php if (in_array($user['role'], [ROLE_EDITOR, ROLE_ADMIN], true)): ?>
        | <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=new">Insert row</a>
    <?php endif; ?>
</p>

<form method="get" class="filter-form">
    <input type="hidden" name="db" value="<?= e($db) ?>">
    <input type="hidden" name="table" value="<?= e($table) ?>">
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
    <label><?= e($name) ?>
        <input type="text" name="filter[<?= e($name) ?>]" value="<?= e($filters[$name] ?? '') ?>">
    </label>
<?php endforeach; ?>
<button type="submit">Filter</button>
</form>

<table class="grid">
<thead><tr>
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; $nextDir = ($sortColumn === $name && $sortDir === 'ASC') ? 'DESC' : 'ASC'; ?>
    <th><a href="?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&sort=<?= e(rawurlencode($name)) ?>&dir=<?= $nextDir ?>"><?= e($name) ?></a></th>
<?php endforeach; ?>
<th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($result['rows'] as $row): ?>
<tr>
<?php foreach ($columns as $col): ?>
    <td><?= e((string) ($row[$col['COLUMN_NAME']] ?? '')) ?></td>
<?php endforeach; ?>
<td>
<?php if ($primaryKey !== null && in_array($user['role'], [ROLE_EDITOR, ROLE_ADMIN], true)): $pkSeg = rawurlencode((string) $row[$primaryKey]); ?>
    <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=edit&pk=<?= e($pkSeg) ?>">Edit</a>
    <form method="post" action="/table.php" class="inline-form" data-confirm="Delete this row?">
        <input type="hidden" name="db" value="<?= e($db) ?>">
        <input type="hidden" name="table" value="<?= e($table) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="pk" value="<?= e((string) $row[$primaryKey]) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button type="submit">Delete</button>
    </form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="pagination">
<?php $totalPages = max(1, (int) ceil($result['total'] / $result['pageSize'])); ?>
Page <?= $result['page'] ?> of <?= $totalPages ?>
<?php if ($result['page'] > 1): ?> <a href="?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&page=<?= $result['page'] - 1 ?>">Previous</a><?php endif; ?>
<?php if ($result['page'] < $totalPages): ?> <a href="?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&page=<?= $result['page'] + 1 ?>">Next</a><?php endif; ?>
</div>
