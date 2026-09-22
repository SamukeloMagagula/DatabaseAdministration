<h1>Databases</h1>
<ul class="db-list">
<?php foreach ($databases as $db): ?>
    <li><a href="/db/<?= \App\View::e($db) ?>/tables"><?= \App\View::e($db) ?></a></li>
<?php endforeach; ?>
</ul>
