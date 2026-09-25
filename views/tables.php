<?php $dbSeg = rawurlencode($db); ?>
<h1>Tables in <?= e($db) ?></h1>
<p><a href="<?= e(url('/database.php?db=' . $dbSeg . '&view=export_sql')) ?>">Export database as SQL</a></p>
<ul class="table-list">
<?php foreach ($tables as $table): $tableSeg = rawurlencode($table); ?>
    <li>
        <a href="<?= e(url('/table.php?db=' . $dbSeg . '&table=' . $tableSeg)) ?>"><?= e($table) ?></a>
        (<a href="<?= e(url('/table.php?db=' . $dbSeg . '&table=' . $tableSeg . '&view=structure')) ?>">structure</a>)
    </li>
<?php endforeach; ?>
</ul>

<?php if (($user['role'] ?? null) === ROLE_ADMIN): ?>
<h2>Copy This Database</h2>
<form method="post" action="<?= e(url('/database.php')) ?>">
    <input type="hidden" name="db" value="<?= e($db) ?>">
    <input type="hidden" name="action" value="copy">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <label>New database name <input type="text" name="new_db" pattern="[A-Za-z0-9_]+" required></label>
    <button type="submit">Copy</button>
</form>
<?php endif; ?>
