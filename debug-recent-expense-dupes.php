<?php
// Read-only — checks whether the Freepik (750 EGP) / Claude (1200 EGP)
// WhatsApp expenses reported today actually landed twice in the DB, or
// whether the add_transaction dedup guard (same type+amount+created_by
// within 240 min) did its job and it was just a confusing recap message.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, ref, type, amount, currency, description, method, created_by, created_at
     FROM expenses
     WHERE created_at >= (NOW() - INTERVAL 6 HOUR)
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " expense(s) in the last 6 hours:\n\n";
foreach ($rows as $r) {
    echo "{$r['ref']} | {$r['type']} | {$r['amount']} {$r['currency']} | {$r['description']} | {$r['method']} | by {$r['created_by']} | {$r['created_at']}\n";
}
