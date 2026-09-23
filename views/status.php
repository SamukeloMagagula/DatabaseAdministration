<h1>Server Status</h1>
<table class="grid">
<tr><th>Version</th><td><?= e($status['version']) ?></td></tr>
<tr><th>Uptime</th><td><?= (int) ($status['uptime'] / 3600) ?> hours</td></tr>
<tr><th>Connections</th><td><?= $status['connections'] ?></td></tr>
</table>

<h2>Database Sizes</h2>
<table class="grid">
<thead><tr><th>Database</th><th>Size (MB)</th></tr></thead>
<tbody>
<?php foreach ($status['sizes'] as $db => $mb): ?>
<tr><td><?= e($db) ?></td><td><?= e((string) $mb) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
