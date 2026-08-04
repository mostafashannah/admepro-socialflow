<?php
// ================================================================
// ONE-OFF backfill — run once, then delete this file.
//
// Publish Date only ever means something for a real social post (has a
// platform) — a plain Task (added via "Add Task", platform is always
// empty) never gets published, only client-approved, so its Publish Date
// is always meaningless and is now hidden in the UI entirely. This just
// clears any scheduled_date/scheduled_time that got set on existing Task
// rows before that field was hidden, so nothing stale is left behind.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->prepare(
    "UPDATE posts SET scheduled_date = NULL, scheduled_time = NULL
     WHERE (platform IS NULL OR platform = '')
       AND (scheduled_date IS NOT NULL OR scheduled_time IS NOT NULL)"
);
$stmt->execute();

echo json_encode(['ok' => true, 'tasks_cleared' => $stmt->rowCount()]) . "\n";
