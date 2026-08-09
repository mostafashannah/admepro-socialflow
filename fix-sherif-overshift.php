<?php
// One-off correction — the capacity-overflow rollover cascaded across
// multiple re-renders (see debug-sherif-overflow.php output) and pushed 6
// of Sherif's "Aug Calendar" tasks to 2026-08-10 when only ~3 genuinely
// didn't fit in the 10am-7pm window. Resets all 6 back to today
// (2026-08-09) so the now-fixed, run-once-per-day logic can correctly
// re-decide which ones actually overflow on next page load.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ids = [
    '0d6e3ba4-fe7a-4c87-9cd8-8b01bb422b36', // Static 1
    '1a031834-67f1-499a-bb8a-b2881a24345a', // Reel 3
    '70ba1acc-e510-4e01-a173-94956149a68a', // Reel 2
    '7c8e3b5f-318c-4f7d-9dc4-edd960a7deba', // Static 3
    '9e2b0987-65c5-4bea-9742-1383a595f18c', // Static 4
    'c681ffdf-983d-4590-96a3-f64f4ba7953d', // Reel 1
];

$in = implode(',', array_fill(0, count($ids), '?'));
$before = $pdo->prepare("SELECT id, title, due_date FROM posts WHERE id IN ($in)");
$before->execute($ids);
echo "Before:\n";
foreach ($before->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";

$upd = $pdo->prepare("UPDATE posts SET due_date = '2026-08-09' WHERE id IN ($in)");
$upd->execute($ids);
echo "\nReset all 6 back to due_date = 2026-08-09.\n";
