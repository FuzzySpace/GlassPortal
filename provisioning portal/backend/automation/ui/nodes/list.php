<?php

require_once '../../backend/db.php';
require_once '../../backend/models/Node.php';

$model = new Node($pdo);
$nodes = $model->all();

?>

<h2>Nodes</h2>

<table border="1">

<tr>
<th>ID</th>
<th>Name</th>
<th>Site</th>
<th>Provider</th>
<th>IP</th>
</tr>

<?php foreach ($nodes as $n): ?>

<tr>
<td><?= $n['id'] ?></td>
<td><?= htmlspecialchars($n['name']) ?></td>
<td><?= htmlspecialchars($n['site']) ?></td>
<td><?= htmlspecialchars($n['provider']) ?></td>
<td><?= htmlspecialchars($n['mgmt_ip']) ?></td>
</tr>

<?php endforeach; ?>

</table>