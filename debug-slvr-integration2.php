<?php
// READ-ONLY diagnostic — any integration row created in the last few
// hours, regardless of client_id/app_key, to find where the new SLVR
// Instagram link attempt actually landed (wrong client_id? wrong app_key?
// never saved at all?).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, name, app_key, status, client_id, client_name, created_at, error_count, last_run_message
     FROM integrations
     WHERE created_at >= (NOW() - INTERVAL 6 HOUR)
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
echo "=== Integrations created in the last 6 hours ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none — nothing was saved at all)\n";
