<?php
/**
 * backfill-performance-logs.php — one-time backfill. Finds every post
 * already sitting at Scheduled or Published with no matching
 * performance_logs row (the live app only started writing one on Scheduled
 * as of the recent fix — before that, only Published did, and only for
 * transitions that happened *after* that code shipped) and creates one,
 * using the same scoring logic the live app uses on a real stage change.
 *
 * Safe to re-run — always skips posts that already have a row.
 *
 * Usage:
 *   php backfill-performance-logs.php          (dry run — prints what it would do)
 *   php backfill-performance-logs.php --apply  (actually inserts)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$apply = in_array('--apply', $argv, true);

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$posts = $pdo->query("
    SELECT p.*
    FROM posts p
    LEFT JOIN performance_logs pl ON pl.post_id = p.id
    WHERE p.stage IN ('scheduled', 'published')
      AND p.assigned_to IS NOT NULL AND p.assigned_to != ''
      AND pl.id IS NULL
    ORDER BY p.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (!$posts) {
    echo json_encode(['found' => 0, 'message' => 'Nothing to backfill.']);
    exit;
}

$teamByEmail = [];
foreach ($pdo->query("SELECT email, name, role FROM team_members")->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $teamByEmail[$t['email']] = $t;
}

$ins = $pdo->prepare("
    INSERT INTO performance_logs
        (id, user_email, user_name, role, post_id, post_title, project_id, client_name,
         stage_from, stage_to, on_time, quality_score, revision_count, client_approved,
         rejected, completed_at)
    VALUES
        (UUID(), :user_email, :user_name, :role, :post_id, :post_title, :project_id, :client_name,
         :stage_from, :stage_to, :on_time, :quality_score, :revision_count, 0,
         :rejected, :completed_at)
");

$created = 0;
$skippedNoTeamMatch = 0;
foreach ($posts as $p) {
    $email = $p['assigned_to'];
    $team = $teamByEmail[$email] ?? null;

    $deadline = $p['due_date'] ?: $p['scheduled_date'];
    // published_at is the real completion moment if it happened; a post
    // still sitting at Scheduled hasn't "completed" yet in that sense, so
    // NOW() is the best available stand-in for this one-time backfill.
    $completedAt = $p['published_at'] ?: date('Y-m-d H:i:s');
    $onTime = !$deadline || strtotime($completedAt) <= strtotime("$deadline 23:59:59");
    $wasRejected = (bool)($p['was_rejected'] ?? false);
    $qualityScore = ($onTime ? 50 : 0) + (!$wasRejected ? 50 : 0);

    if ($apply) {
        $ins->execute([
            ':user_email' => $email,
            ':user_name' => $team['name'] ?? '',
            ':role' => $team['role'] ?? '',
            ':post_id' => $p['id'],
            ':post_title' => $p['title'],
            ':project_id' => $p['project_id'] ?: '',
            ':client_name' => $p['client_name'] ?: '',
            ':stage_from' => 'backfill',
            ':stage_to' => $p['stage'],
            ':on_time' => $onTime ? 1 : 0,
            ':quality_score' => $qualityScore,
            ':revision_count' => (int)($p['revision_count'] ?? 0),
            ':rejected' => $wasRejected ? 1 : 0,
            ':completed_at' => $completedAt,
        ]);
    }
    if (!$team) $skippedNoTeamMatch++;
    $created++;
}

echo json_encode([
    'dry_run' => !$apply,
    'found' => count($posts),
    ($apply ? 'inserted' : 'would_insert') => $created,
    'no_team_member_match' => $skippedNoTeamMatch,
]);
