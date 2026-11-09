<?php
/**
 * auto-timer-cron.php — starts each person's task timer by itself when the task's timeline slot begins,
 * and pauses it when the slot ends (or the task moves on). Nobody has to press Start or even have the app open.
 * Slot = [due_time - estimated_minutes, due_time], same as the Timeline. Tasks without a due_time are not auto-timed.
 * Never starts a second timer for someone who already has one running, and never restarts a task they already
 * started/paused today. Run every minute:
 *   * * * * * /usr/bin/php /var/www/socialflow/auto-timer-cron.php >> /var/www/socialflow/auto-timer-cron.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

$OWNED = ['content_creator' => 'content_creation', 'graphic_designer' => 'design', 'senior_graphic_designer' => 'design', 'video_editor' => 'design', 'senior_video_editor' => 'design'];
$WORK_START = 11 * 60; $WORK_END = 21 * 60;

$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$today = $now->format('Y-m-d');
$nowMins = (int)$now->format('H') * 60 + (int)$now->format('i');
$utcNow = gmdate('Y-m-d H:i:s');
$utcDate = gmdate('Y-m-d');

$members = [];
foreach ($pdo->query("SELECT email, name, role FROM team_members WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    if (isset($OWNED[$m['role']])) $members[strtolower($m['email'])] = $m;
}

// Today's tasks per owner, laid out exactly like the Timeline: highest priority first, each anchored at
// (due_time - estimate), and a task that collides with one already placed is pushed to start right after it.
$todayPosts = $pdo->prepare("SELECT id, title, project_id, stage, priority, scheduled_date, assigned_to, design_assigned_to, content_assigned_to, due_date, due_time, estimated_minutes
    FROM posts WHERE LEFT(due_date, 10) = ? AND due_time IS NOT NULL AND due_time <> '' AND stage IN ('design','content_creation') AND (is_plan_parent IS NULL OR is_plan_parent = 0) ORDER BY created_at DESC");
$todayPosts->execute([date('Y-m-d', strtotime($today))]);
$prio = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
$byOwner = [];
foreach ($todayPosts->fetchAll(PDO::FETCH_ASSOC) as $p) {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $p['due_time'], $mm)) continue;
    $stage = $p['stage'];
    $owner = $stage === 'design' ? ($p['design_assigned_to'] ?: $p['assigned_to']) : ($p['content_assigned_to'] ?: $p['assigned_to']);
    $m = $members[strtolower((string)$owner)] ?? null;
    if (!$m || $OWNED[$m['role']] !== $stage) continue;
    $score = ($prio[$p['priority']] ?? 1) * 10;
    if (!empty($p['scheduled_date'])) $score += max(0, 7 - (int)floor((strtotime($p['scheduled_date']) - time()) / 86400));
    $p['_score'] = $score; $p['_due'] = (int)$mm[1] * 60 + (int)$mm[2];
    $p['_est'] = (int)$p['estimated_minutes'] > 0 ? (int)$p['estimated_minutes'] : 60;
    $p['_member'] = $m;
    $byOwner[strtolower($m['email'])][] = $p;
}
$placed = []; // post_id => [start, end, member, post]
foreach ($byOwner as $list) {
    usort($list, fn($a, $b) => $b['_score'] <=> $a['_score']);
    $taken = [];
    foreach ($list as $p) {
        $est = $p['_est'];
        $cursor = max($WORK_START, min($p['_due'] - $est, $WORK_END - $est));
        $guard = 0;
        do {
            $hit = null;
            foreach ($taken as $t) { if ($t[0] < $cursor + $est && $t[1] > $cursor) { $hit = $t; break; } }
            if ($hit) $cursor = $hit[1];
        } while ($hit && $guard++ < 50);
        $taken[] = [$cursor, $cursor + $est];
        $placed[$p['id']] = ['start' => $cursor, 'end' => $cursor + $est, 'member' => $p['_member'], 'post' => $p];
    }
}

$debug = in_array('debug', $argv ?? [], true);
$dbg = function ($m) use ($debug) { if ($debug) echo "  · $m\n"; };
$dbg('members timed: ' . count($members) . ' | now ' . $now->format('Y-m-d H:i') . " (mins $nowMins) | utc date $utcDate");
$started = 0; $paused = 0;

// 1) Pause auto-started timers whose slot has ended or whose task moved on.
$act = $pdo->query("SELECT * FROM time_entries WHERE status = 'active' AND auto_started = 1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($act as $e) {
    $slot = $placed[$e['post_id']] ?? null;
    if ($slot && $nowMins >= $slot['start'] && $nowMins < $slot['end']) continue; // still inside its slot (a timer outside it — e.g. started under an old layout — is paused)
    $st = strtotime(str_replace('T', ' ', rtrim((string)$e['started_at'], 'Z')) . ' UTC');
    $total = (float)$e['total_seconds'] + ($st ? max(0, time() - $st) : 0);
    $pdo->prepare("UPDATE time_entries SET status = 'paused', paused_at = ?, total_seconds = ? WHERE id = ?")->execute([$utcNow, $total, $e['id']]);
    $paused++;
}

// 2) Start timers for slots that are open right now (earliest slot first).
$hasActive = $pdo->prepare("SELECT 1 FROM time_entries WHERE user_email = ? AND status = 'active' LIMIT 1");
$hasToday = $pdo->prepare("SELECT 1 FROM time_entries WHERE user_email = ? AND post_id = ? AND date = ? LIMIT 1");
$ins = $pdo->prepare("INSERT INTO time_entries (id, post_id, post_title, user_email, user_name, started_at, date, status, total_seconds, project_id, auto_started) VALUES (UUID(), ?, ?, ?, ?, ?, ?, 'active', 0, ?, 1)");
uasort($placed, fn($a, $b) => $a['start'] <=> $b['start']);
foreach ($placed as $pid => $slot) {
    $p = $slot['post'];
    $dbg("{$p['title']}: slot " . sprintf('%02d:%02d', intdiv($slot['start'], 60), $slot['start'] % 60) . '-' . sprintf('%02d:%02d', intdiv($slot['end'], 60), $slot['end'] % 60) . " owner {$slot['member']['email']}");
    if ($nowMins < $slot['start'] || $nowMins >= $slot['end']) { $dbg('   outside slot now'); continue; }
    $m = $slot['member'];
    $hasActive->execute([$m['email']]); if ($hasActive->fetchColumn()) { $dbg('   owner already has a running timer'); continue; }
    $hasToday->execute([$m['email'], $pid, $utcDate]); if ($hasToday->fetchColumn()) { $dbg('   already has an entry today'); continue; }
    $ins->execute([$pid, $p['title'], $m['email'], $m['name'], $utcNow, $utcDate, $p['project_id'] ?: '']);
    $started++;
}
echo json_encode(['started' => $started, 'paused' => $paused, 'at' => $now->format('H:i')]) . "\n";
