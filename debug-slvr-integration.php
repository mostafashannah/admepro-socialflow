<?php
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cols = $pdo->query("SHOW COLUMNS FROM integrations")->fetchAll(PDO::FETCH_COLUMN);
echo "=== integrations columns ===\n" . implode(", ", $cols) . "\n";

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%SLVR%'")->fetch(PDO::FETCH_ASSOC);
echo "\n=== Client ===\n" . json_encode($client) . "\n";

$stmt = $pdo->prepare("SELECT * FROM integrations WHERE client_id = :cid OR client_id IS NULL ORDER BY created_at DESC LIMIT 15");
$stmt->execute([':cid' => $client['id'] ?? '']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Recent integrations (this client + global) ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";
