<h1>Tables in <?= \App\View::e($db) ?></h1>
<ul class="table-list">
<?php foreach ($tables as $table): ?>
    <li>
        <a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>"><?= \App\View::e($table) ?></a>
        (<a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/structure">structure</a>)
    </li>
<?php endforeach; ?>
</ul>
