<?php
// READ-ONLY diagnostic — ce() (the generic Supabase-style insert helper)
// logs an ActivityLog row named "Save Failed: <Entity>" whenever a POST
// comes back non-2xx, with the real HTTP status + response body in the
// details field. Checking for one from today to find the exact reason
// the new SLVR Instagram integration never actually got saved.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cols = $pdo->query("SHOW COLUMNS FROM activity_logs")->fetchAll(PDO::FETCH_COLUMN);
echo "=== activity_logs columns ===\n" . implode(", ", $cols) . "\n\n";

$rows = $pdo->query(
    "SELECT * FROM activity_logs
     WHERE (action LIKE '%Save Failed%' OR action LIKE '%Integration%')
       AND created_at >= (NOW() - INTERVAL 12 HOUR)
     ORDER BY created_at DESC
     LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);
echo "=== Integration-related activity log entries (last 12h) ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";
