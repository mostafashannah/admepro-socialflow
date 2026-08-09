<?php
// READ-ONLY diagnostic — lists Instagram reels missing a carousel_cover,
// and any post with design_assets that should carry a story-tagged item
// but doesn't, to figure out what (if anything) actually needs backfilling
// versus just being a display fix that already works live.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Instagram reels missing carousel_cover ===\n";
$rows = $pdo->query("SELECT id, title, stage, created_at FROM posts WHERE post_type='reel' AND platform='instagram' AND (carousel_cover IS NULL OR carousel_cover='') ORDER BY created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";

echo "\n=== Posts with a design_assets item tagged kind=story ===\n";
$rows2 = $pdo->query("SELECT id, title, stage, design_assets FROM posts WHERE design_assets LIKE '%\"kind\":\"story\"%' ORDER BY created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) echo "{$r['id']} — {$r['title']} — stage={$r['stage']}\n";
if (!$rows2) echo "(none)\n";
