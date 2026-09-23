<?php $dbSeg = rawurlencode($db); ?>
<h1>Tables in <?= e($db) ?></h1>
<ul class="table-list">
<?php foreach ($tables as $table): $tableSeg = rawurlencode($table); ?>
    <li>
        <a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>"><?= e($table) ?></a>
        (<a href="/table.php?db=<?= e($dbSeg) ?>&table=<?= e($tableSeg) ?>&view=structure">structure</a>)
    </li>
<?php endforeach; ?>
</ul>
