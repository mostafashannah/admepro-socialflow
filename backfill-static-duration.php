<?php
// ================================================================
// ONE-OFF backfill — run once, then delete this file.
//
// The estimation method is currently set to "Manual" (Settings → Task
// Estimates), which ignores the per-type duration table entirely and
// falls back to a flat 60 min for any task with no explicit
// estimated_minutes set — so existing "static" posts show 60 min instead
// of the new 45 min default just given to that type. Writes 45 min
// directly onto every static post that has no real estimate of its own
// yet, so they read correctly right away instead of only affecting new
// posts going forward.
//
// Never touches a post that already has an explicit estimated_minutes —
// only fills in the ones currently falling back to the flat placeholder.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$stmt = $pdo->prepare(
    "UPDATE posts SET estimated_minutes = 45 WHERE post_type = 'static' AND (estimated_minutes IS NULL OR estimated_minutes = 0)"
);
$stmt->execute();

echo "Backfilled estimated_minutes = 45 on {$stmt->rowCount()} static post(s).\n";
