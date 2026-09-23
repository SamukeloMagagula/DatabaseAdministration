<?php $dbSeg = rawurlencode($db); $tableSeg = rawurlencode($table); ?>
<h1><?= \App\View::e($db) ?>.<?= \App\View::e($table) ?></h1>
<p>
    <a href="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>/structure">View structure</a>
    <?php if (in_array($user['role'], [\App\Roles::EDITOR, \App\Roles::ADMIN], true)): ?>
        | <a href="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>/new">Insert row</a>
    <?php endif; ?>
</p>

<form method="get" class="filter-form">
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
    <label><?= \App\View::e($name) ?>
        <input type="text" name="filter[<?= \App\View::e($name) ?>]" value="<?= \App\View::e($filters[$name] ?? '') ?>">
    </label>
<?php endforeach; ?>
<button type="submit">Filter</button>
</form>

<table class="grid">
<thead><tr>
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; $nextDir = ($sortColumn === $name && $sortDir === 'ASC') ? 'DESC' : 'ASC'; ?>
    <th><a href="?sort=<?= \App\View::e($name) ?>&dir=<?= $nextDir ?>"><?= \App\View::e($name) ?></a></th>
<?php endforeach; ?>
<th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($result['rows'] as $row): ?>
<tr>
<?php foreach ($columns as $col): ?>
    <td><?= \App\View::e((string) ($row[$col['COLUMN_NAME']] ?? '')) ?></td>
<?php endforeach; ?>
<td>
<?php if ($primaryKey !== null && in_array($user['role'], [\App\Roles::EDITOR, \App\Roles::ADMIN], true)): $pkSeg = rawurlencode((string) $row[$primaryKey]); ?>
    <a href="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>/row/<?= \App\View::e($pkSeg) ?>/edit">Edit</a>
    <form method="post" action="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>/row/<?= \App\View::e($pkSeg) ?>/delete" style="display:inline" onsubmit="return confirm('Delete this row?');">
        <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
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
<?php if ($result['page'] > 1): ?> <a href="?page=<?= $result['page'] - 1 ?>">Previous</a><?php endif; ?>
<?php if ($result['page'] < $totalPages): ?> <a href="?page=<?= $result['page'] + 1 ?>">Next</a><?php endif; ?>
</div>
