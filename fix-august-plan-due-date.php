<?php
// One-off correction — v1125 briefly rewrote a genuinely-overdue task's
// due_date to "today" (a design mistake, since reverted in the app code:
// the OVERDUE flag is meant to stay purely a live comparison against the
// ORIGINAL due_date, not one that overwrites itself away). "August Plan"
// (id 92c50995-a896-492d-b562-7d149529c7ba) got its due_date bumped from
// 2026-08-05 to 2026-08-09 by that logic before the fix — this restores
// the real original due_date so the Timeline's overdue detection works
// correctly again (red label + queue-jump, without colliding with today's
// due_time-anchored tasks).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$id = '92c50995-a896-492d-b562-7d149529c7ba';
$row = $pdo->prepare("SELECT id, title, due_date, due_time FROM posts WHERE id = ?");
$row->execute([$id]);
$before = $row->fetch(PDO::FETCH_ASSOC);
echo "Before: " . json_encode($before) . "\n";

if ($before && $before['due_date'] !== '2026-08-05') {
    $upd = $pdo->prepare("UPDATE posts SET due_date = '2026-08-05' WHERE id = ?");
    $upd->execute([$id]);
    echo "Restored due_date to 2026-08-05.\n";
} else {
    echo "Already correct or not found — no change made.\n";
}
