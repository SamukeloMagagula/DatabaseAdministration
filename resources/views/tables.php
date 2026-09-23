<?php $dbSeg = rawurlencode($db); ?>
<h1>Tables in <?= \App\View::e($db) ?></h1>
<ul class="table-list">
<?php foreach ($tables as $table): $tableSeg = rawurlencode($table); ?>
    <li>
        <a href="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>"><?= \App\View::e($table) ?></a>
        (<a href="/db/<?= \App\View::e($dbSeg) ?>/table/<?= \App\View::e($tableSeg) ?>/structure">structure</a>)
    </li>
<?php endforeach; ?>
</ul>
