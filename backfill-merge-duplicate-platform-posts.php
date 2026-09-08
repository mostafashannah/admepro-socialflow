<?php
// One-off: merges posts that got split into one row per platform by the
// old "Ready Content" flow (see AddPostModal's ready branch / addReadyContent
// in app.jsx) back into a single row with a combined platforms array —
// same title/caption/scheduled_date/client_id/post_type but a different
// single platform each is the exact fingerprint that flow used to produce.
// Keeps the earliest row of each group, folds the others' platforms into
// it, and deletes the rest. Never touches groups that only have one row.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, title, caption, scheduled_date, client_id, post_type, platform, platforms, stage, created_at
     FROM posts
     WHERE title IS NOT NULL AND title != ''
     ORDER BY created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $r) {
    $key = implode('|', [
        trim((string)$r['title']), trim((string)$r['caption']),
        (string)$r['scheduled_date'], (string)$r['client_id'], (string)$r['post_type'],
    ]);
    $groups[$key][] = $r;
}

$update = $pdo->prepare("UPDATE posts SET platform = :platform, platforms = :platforms WHERE id = :id");
$delete = $pdo->prepare("DELETE FROM posts WHERE id = :id");

$mergedGroups = 0;
$deletedRows = 0;
foreach ($groups as $key => $items) {
    if (count($items) < 2) continue;
    $platforms = array_values(array_unique(array_filter(array_column($items, 'platform'))));
    if (count($platforms) < 2) continue; // same title/caption/date but not actually different platforms — leave alone

    $keep = $items[0];
    echo "Merging \"{$keep['title']}\" ({$keep['scheduled_date']}) — platforms: " . implode(', ', $platforms) . " — keeping {$keep['id']}\n";
    $update->execute([':platform' => $platforms[0], ':platforms' => json_encode($platforms), ':id' => $keep['id']]);
    for ($i = 1; $i < count($items); $i++) {
        echo "  Deleting duplicate {$items[$i]['id']} (platform={$items[$i]['platform']})\n";
        $delete->execute([':id' => $items[$i]['id']]);
        $deletedRows++;
    }
    $mergedGroups++;
}

echo json_encode(['ok' => true, 'merged_groups' => $mergedGroups, 'deleted_rows' => $deletedRows]) . "\n";
