<h1>Databases</h1>
<ul class="db-list">
<?php foreach ($databases as $db): ?>
    <li><a href="<?= e(url('/database.php?db=' . rawurlencode($db))) ?>"><?= e($db) ?></a></li>
<?php endforeach; ?>
</ul>
