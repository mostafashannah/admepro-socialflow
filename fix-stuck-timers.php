<?php
// One-off correction — the two currently-active time_entries rows found
// earlier have started_at values that, combined with the browser-side
// mis-parsing bug (now fixed), left their displayed counters stuck. Rather
// than guess whether their original started_at was "real", just pause
// them cleanly with total_seconds=0 so the next Start/Resume the user
// does begins from a known-good state under the fixed code.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ids = ['25147b86-0e5d-4fee-af9d-bc79c18f4c02', 'bb949022-24b6-4ed2-a598-b7014c7c0fb6'];
$in = implode(',', array_fill(0, count($ids), '?'));

$before = $pdo->prepare("SELECT id, post_id, user_email, started_at, status, total_seconds FROM time_entries WHERE id IN ($in)");
$before->execute($ids);
echo "Before:\n";
foreach ($before->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";

$upd = $pdo->prepare("UPDATE time_entries SET status = 'paused', total_seconds = 0, paused_at = UTC_TIMESTAMP() WHERE id IN ($in)");
$upd->execute($ids);
echo "\nReset both stuck entries to paused/0.\n";
