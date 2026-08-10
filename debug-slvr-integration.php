<?php
// READ-ONLY diagnostic — every integration row for SLVR (any status/app_key),
// most recent first, to see why a just-linked Instagram integration isn't
// showing as connected. The app only treats a row as "connected" when
// status==='active' AND app_key matches AND client_id matches (or is null
// for a global connection) — so this shows whether the row even exists,
// and if so what status/client_id it actually landed with.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%SLVR%' OR name LIKE '%SLVR%'")->fetch(PDO::FETCH_ASSOC);
echo "=== Client ===\n" . json_encode($client) . "\n";

$stmt = $pdo->prepare(
    "SELECT id, app_key, status, client_id, account_name, external_account_id, created_at, error_message
     FROM integrations
     WHERE client_id = :cid OR client_id IS NULL
     ORDER BY created_at DESC
     LIMIT 15"
);
$stmt->execute([':cid' => $client['id'] ?? '']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Recent integrations (this client + global) ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";
