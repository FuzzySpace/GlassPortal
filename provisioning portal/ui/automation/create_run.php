<?php

require_once '../../backend/db.php';

$nodes = $pdo->query("SELECT id, name FROM nodes")->fetchAll();

?>

<h2>Create Automation Run</h2>

<form method="POST" action="../../backend/automation/run_automation.php">

Automation ID
<input type="number" name="automation_id" required>

Node
<select name="node_id">
<?php foreach ($nodes as $n): ?>
<option value="<?= $n['id'] ?>">
<?= htmlspecialchars($n['name']) ?>
</option>
<?php endforeach; ?>
</select>

<button type="submit">Run Automation</button>

</form>