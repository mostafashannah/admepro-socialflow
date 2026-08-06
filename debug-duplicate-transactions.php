<?php
// READ-ONLY diagnostic — lists today's expenses grouped by (type, amount)
// where more than one row exists, to find the real duplicates created by
// the false-success-claim bug in askPro (see pro-lib.php's $asksQuestion
// fix). Run this first, confirm which rows are true duplicates, then
// delete the extras manually (or ask for a targeted delete script).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, ref, type, amount, description, created_by, created_at
     FROM expenses WHERE DATE(created_at) = CURDATE() ORDER BY type, amount, created_at"
)->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $r) {
    $key = $r['type'] . '|' . $r['amount'];
    $groups[$key][] = $r;
}
foreach ($groups as $key => $items) {
    if (count($items) < 2) continue;
    echo "=== " . str_replace('|', ' EGP ', $key) . " — " . count($items) . " rows ===\n";
    foreach ($items as $r) {
        echo "  [{$r['ref']}] {$r['description']} — by {$r['created_by']} — {$r['created_at']}\n";
    }
}
