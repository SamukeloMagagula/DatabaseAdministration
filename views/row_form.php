<h1><?= $row === null ? 'Insert row into' : 'Edit row in' ?> <?= e($db) ?>.<?= e($table) ?></h1>
<form method="post" action="table.php">
    <input type="hidden" name="db" value="<?= e($db) ?>">
    <input type="hidden" name="table" value="<?= e($table) ?>">
    <input type="hidden" name="action" value="<?= $row === null ? 'insert' : 'update' ?>">
    <?php if ($row !== null): ?><input type="hidden" name="pk" value="<?= e((string) $pkValue) ?>"><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
        <?php if ($row === null || $name !== $primaryKey): ?>
        <label><?= e($name) ?> (<?= e($col['DATA_TYPE']) ?>)
            <input type="text" name="fields[<?= e($name) ?>]" value="<?= e((string) ($row[$name] ?? $col['COLUMN_DEFAULT'] ?? '')) ?>">
        </label>
        <?php else: ?>
        <p><?= e($name) ?>: <?= e((string) $row[$name]) ?> (primary key, not editable)</p>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit"><?= $row === null ? 'Insert' : 'Save' ?></button>
</form>
