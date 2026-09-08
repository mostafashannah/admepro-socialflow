<?php
// One-off — removes the confirmed duplicate expense row created by the
// force=true loophole (see pro-lib.php add_transaction fix): two identical
// "750 EGP out — Freepik subscription" rows 32 seconds apart
// (TXN-A9CB2BAE at 15:44:04, TXN-0690FAF8 at 15:44:36). Keeps the first
// (real) one, deletes the second (duplicate).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->prepare("SELECT id, ref, type, amount, description, created_at FROM expenses WHERE ref IN ('TXN-A9CB2BAE', 'TXN-0690FAF8')");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " row(s):\n";
foreach ($rows as $r) echo "  {$r['ref']} | {$r['type']} | {$r['amount']} | {$r['description']} | {$r['created_at']}\n";

$dupRow = null;
foreach ($rows as $r) if ($r['ref'] === 'TXN-0690FAF8') $dupRow = $r;

if (!$dupRow) { echo "\nTXN-0690FAF8 not found — nothing to delete (may already be removed).\n"; exit; }

$del = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
$del->execute([':id' => $dupRow['id']]);
echo "\nDeleted duplicate {$dupRow['ref']} ({$dupRow['amount']} {$dupRow['description']}).\n";
