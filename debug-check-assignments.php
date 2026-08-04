<?php
// READ-ONLY diagnostic — run once, then delete. Prints the raw stage-
// change log entries mentioning a given name, so we can see exactly what
// stage they were assigned into (only "Moved to Content"/"Moved to
// Design" get backfilled into content_assigned_to/design_assigned_to —
// this shows whether that's actually what's in their history or not).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$names = $argv[1] ?? 'Mostafa Elnady,Eyad Abdelalem';
foreach (explode(',', $names) as $name) {
    $name = trim($name);
    $stmt = $pdo->prepare("SELECT post_id, content, created_at FROM comments WHERE type='stage_change' AND content LIKE ? ORDER BY created_at");
    $stmt->execute(['%assigned to ' . $name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "== $name (" . count($rows) . ") ==\n";
    foreach ($rows as $r) echo "  [{$r['created_at']}] post={$r['post_id']}: {$r['content']}\n";
    echo "\n";
}
