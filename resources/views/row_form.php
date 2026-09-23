<?php $dbSeg = rawurlencode($db); $tableSeg = rawurlencode($table); ?>
<h1><?= $row === null ? 'Insert row into' : 'Edit row in' ?> <?= \App\View::e($db) ?>.<?= \App\View::e($table) ?></h1>
<form method="post" action="<?= $row === null
    ? '/db/' . \App\View::e($dbSeg) . '/table/' . \App\View::e($tableSeg) . '/rows'
    : '/db/' . \App\View::e($dbSeg) . '/table/' . \App\View::e($tableSeg) . '/row/' . \App\View::e(rawurlencode((string) $pkValue)) ?>">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
        <?php if ($row === null || $name !== $primaryKey): ?>
        <label><?= \App\View::e($name) ?> (<?= \App\View::e($col['DATA_TYPE']) ?>)
            <input type="text" name="fields[<?= \App\View::e($name) ?>]" value="<?= \App\View::e((string) ($row[$name] ?? $col['COLUMN_DEFAULT'] ?? '')) ?>">
        </label>
        <?php else: ?>
        <p><?= \App\View::e($name) ?>: <?= \App\View::e((string) $row[$name]) ?> (primary key, not editable)</p>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit"><?= $row === null ? 'Insert' : 'Save' ?></button>
</form>
