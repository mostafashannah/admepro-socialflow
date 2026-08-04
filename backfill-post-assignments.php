<?php
// ================================================================
// ONE-OFF backfill — run once, then delete this file.
//
// content_assigned_to / design_assigned_to only ever get stamped going
// FORWARD (see handleStageChange in app.jsx, the moment someone is
// assigned into Content/Design) — every post that already passed through
// those stages before that logic existed has those columns empty, so its
// original content creator/designer's dashboard counters and "my work"
// lists still don't count it, even after the fix.
//
// The real history already exists as stage_change Comments — every stage
// move logs "Moved to <Stage> → assigned to <Name>" (see handleStageChange).
// This replays that log per post, in order, and backfills whichever of
// content_assigned_to/design_assigned_to is still empty from the last
// "Moved to Content"/"Moved to Design" entry found. Never overwrites a
// value that's already set (by this same logic or by the live app going
// forward) — purely fills in the gaps.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// name (lowercased, trimmed) -> email — first active match wins on a
// collision, same convention attendance-import.php already uses.
$members = $pdo->query("SELECT name, email, status FROM team_members ORDER BY (status <> 'inactive') DESC")->fetchAll(PDO::FETCH_ASSOC);
$byName = [];
foreach ($members as $m) {
    $key = mb_strtolower(trim($m['name']));
    if (!isset($byName[$key])) $byName[$key] = $m['email'];
}

$rows = $pdo->query(
    "SELECT post_id, content, created_at FROM comments WHERE type = 'stage_change' ORDER BY post_id, created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$byPost = []; // post_id => ['content_assigned_to' => email|null, 'design_assigned_to' => email|null]
foreach ($rows as $r) {
    if (!preg_match('/^Moved to (.+?) \x{2192} assigned to (.+)$/u', trim($r['content']), $m)) continue;
    $stageLabel = trim($m[1]);
    $name = trim($m[2]);
    $email = $byName[mb_strtolower($name)] ?? null;
    if (!$email) continue;

    $field = null;
    if ($stageLabel === 'Content') $field = 'content_assigned_to';
    elseif ($stageLabel === 'Design') $field = 'design_assigned_to';
    if (!$field) continue;

    if (!isset($byPost[$r['post_id']])) $byPost[$r['post_id']] = [];
    $byPost[$r['post_id']][$field] = $email; // later rows overwrite earlier ones — last assignment wins, same as live behavior
}

$updateStmt = $pdo->prepare(
    "UPDATE posts SET
       content_assigned_to = CASE WHEN (content_assigned_to IS NULL OR content_assigned_to = '') AND :ca IS NOT NULL THEN :ca ELSE content_assigned_to END,
       design_assigned_to = CASE WHEN (design_assigned_to IS NULL OR design_assigned_to = '') AND :da IS NOT NULL THEN :da ELSE design_assigned_to END
     WHERE id = :id"
);

$updated = 0;
foreach ($byPost as $postId => $fields) {
    $ca = $fields['content_assigned_to'] ?? null;
    $da = $fields['design_assigned_to'] ?? null;
    if ($ca === null && $da === null) continue;
    $updateStmt->execute([':ca' => $ca, ':da' => $da, ':id' => $postId]);
    if ($updateStmt->rowCount() > 0) $updated++;
}

echo json_encode(['ok' => true, 'posts_scanned' => count($byPost), 'posts_updated' => $updated]) . "\n";
