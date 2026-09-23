<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($db) ?>.<?= e($table) ?></title>
<style>
body { font-family: sans-serif; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #999; padding: 4px 8px; text-align: left; }
</style>
</head>
<body>
<h1><?= e($db) ?>.<?= e($table) ?></h1>
<p>Use your browser's Print option and choose "Save as PDF" to export this as a document.</p>
<table>
<thead><tr>
<?php foreach ($columns as $col): ?><th><?= e($col['COLUMN_NAME']) ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr><?php foreach ($columns as $col): ?><td><?= e((string) ($row[$col['COLUMN_NAME']] ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
