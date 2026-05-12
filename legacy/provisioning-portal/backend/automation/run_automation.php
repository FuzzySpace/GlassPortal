<?php

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed");
}

$automationId = (int)($_POST['automation_id'] ?? 0);
$nodeId = (int)($_POST['node_id'] ?? 0);

if (!$automationId || !$nodeId) {
    http_response_code(400);
    exit("Missing parameters");
}

$meta = [
    'targets' => [
        'node_id' => $nodeId
    ]
];

$stmt = $pdo->prepare("
    INSERT INTO automation_runs
    (automation_id, status, meta)
    VALUES (?, 'queued', ?)
");

$stmt->execute([
    $automationId,
    json_encode($meta)
]);

echo "Run created with ID: " . $pdo->lastInsertId();