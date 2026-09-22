<h1>SQL Console</h1>
<form method="post" action="/sql">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <textarea name="sql" rows="6" cols="80"><?= \App\View::e($sql) ?></textarea><br>
    <button type="submit">Run</button>
</form>

<?php if ($result !== null): ?>
    <?php if (!$result['ok']): ?>
        <p class="error"><?= \App\View::e($result['error']) ?></p>
    <?php elseif ($result['rows'] !== null): ?>
        <p><?= (int) $result['rowCount'] ?> row(s) returned.</p>
        <?php if ($result['rows']): ?>
        <table class="grid">
        <thead><tr><?php foreach (array_keys($result['rows'][0]) as $col): ?><th><?= \App\View::e($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr><?php foreach ($row as $value): ?><td><?= \App\View::e((string) $value) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php endif; ?>
    <?php else: ?>
        <p><?= (int) $result['rowCount'] ?> row(s) affected.</p>
    <?php endif; ?>
<?php endif; ?>
