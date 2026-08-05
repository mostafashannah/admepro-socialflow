<?php
// ================================================================
// ONE-OFF backfill — run once, then delete this file.
//
// Mirrors every existing comment.file_url onto its post's design_assets
// (the same list the persistent Attachments section reads) — sendComment
// only started doing this going forward as of the "attachments
// disappearing" fix, so anything attached via the comment box before that
// (e.g. a PDF like "broadcast.pdf") never made it into design_assets and
// stayed invisible outside the Activity feed. Safe to re-run — skips a
// file_url already present on that post's design_assets.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT c.id, c.post_id, c.file_url, c.file_name, c.file_type
     FROM comments c
     WHERE c.file_url IS NOT NULL AND c.file_url != ''
     ORDER BY c.created_at"
)->fetchAll(PDO::FETCH_ASSOC);

$postStmt = $pdo->prepare("SELECT design_assets FROM posts WHERE id = ?");
$updateStmt = $pdo->prepare("UPDATE posts SET design_assets = ? WHERE id = ?");

$byPost = []; // post_id => decoded design_assets array, loaded once per post
$updated = 0;
foreach ($rows as $r) {
    $postId = $r['post_id'];
    if (!isset($byPost[$postId])) {
        $postStmt->execute([$postId]);
        $existing = $postStmt->fetchColumn();
        if ($existing === false) continue; // post no longer exists
        $decoded = json_decode($existing ?: '[]', true);
        $byPost[$postId] = is_array($decoded) ? $decoded : [];
    }
    $already = false;
    foreach ($byPost[$postId] as $a) {
        if (($a['url'] ?? null) === $r['file_url']) { $already = true; break; }
    }
    if ($already) continue;
    $byPost[$postId][] = ['url' => $r['file_url'], 'name' => $r['file_name'], 'type' => $r['file_type']];
    $updated++;
}

foreach ($byPost as $postId => $assets) {
    $updateStmt->execute([json_encode($assets), $postId]);
}

echo json_encode(['ok' => true, 'comment_attachments_scanned' => count($rows), 'posts_touched' => count($byPost), 'assets_added' => $updated]) . "\n";
