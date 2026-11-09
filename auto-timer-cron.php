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
$WORK_START = 11 * 60; $WORK_END = 23 * 60;

$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$today = $now->format('Y-m-d');
$nowMins = (int)$now->format('H') * 60 + (int)$now->format('i');
$utcNow = gmdate('Y-m-d H:i:s');
$utcDate = gmdate('Y-m-d');

$members = []; $allMembers = [];
foreach ($pdo->query("SELECT email, name, role FROM team_members WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $allMembers[strtolower($m['email'])] = $m;
    if (isset($OWNED[$m['role']])) $members[strtolower($m['email'])] = $m;
}

// Today's tasks per owner, laid out exactly like the Timeline: highest priority first, each anchored at
// (due_time - estimate), and a task that collides with one already placed is pushed to start right after it.
$todayPosts = $pdo->prepare("SELECT id, title, client_name, project_id, stage, priority, scheduled_date, is_routine, assigned_to, design_assigned_to, content_assigned_to, due_date, due_time, estimated_minutes
    FROM posts WHERE LEFT(due_date, 10) = ? AND due_time IS NOT NULL AND due_time <> '' AND ((stage IN ('design','content_creation') AND (is_routine IS NULL OR is_routine = 0)) OR (is_routine = 1 AND routine_status = 'open')) AND (is_plan_parent IS NULL OR is_plan_parent = 0) ORDER BY created_at DESC");
$todayPosts->execute([date('Y-m-d', strtotime($today))]);
$prio = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
$byOwner = [];
foreach ($todayPosts->fetchAll(PDO::FETCH_ASSOC) as $p) {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $p['due_time'], $mm)) continue;
    $stage = $p['stage'];
    if (!empty($p['is_routine'])) {
        // Routine tasks belong to whoever they're assigned to, whatever their role.
        $m = $allMembers[strtolower((string)$p['assigned_to'])] ?? null;
        if (!$m) continue;
    } else {
        $owner = $stage === 'design' ? ($p['design_assigned_to'] ?: $p['assigned_to']) : ($p['content_assigned_to'] ?: $p['assigned_to']);
        $m = $members[strtolower((string)$owner)] ?? null;
        if (!$m || $OWNED[$m['role']] !== $stage) continue;
    }
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
    // An entry for this task already exists today: if the script started it and paused it (slot ended / layout changed),
    // resume it now that its slot is open again; a task the person paused by hand is left alone.
    $existing = $pdo->prepare("SELECT id, status, auto_started FROM time_entries WHERE user_email = ? AND post_id = ? AND date = ? ORDER BY created_at DESC LIMIT 1");
    $existing->execute([$m['email'], $pid, $utcDate]); $ex = $existing->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
        if ($ex['status'] === 'paused' && (int)$ex['auto_started'] === 1) {
            $pdo->prepare("UPDATE time_entries SET status = 'active', started_at = ? WHERE id = ?")->execute([$utcNow, $ex['id']]);
            $started++; $dbg('   resumed paused auto timer');
        } else { $dbg('   already has an entry today'); }
        continue;
    }
    $ins->execute([$pid, $p['title'], $m['email'], $m['name'], $utcNow, $utcDate, $p['project_id'] ?: '']);
    $started++;
}

// 3) Email alerts to the task owner: (a) when their slot starts, (b) 5 minutes before the due time — deliver and close it to protect their score.
// Each alert goes out once per task per day (task_alert_log). Respects notification preferences.
function alertPrefsAllow(PDO $pdo, string $email): bool {
    $st = $pdo->prepare("SELECT all_disabled, mentions_only, task_due_soon FROM notification_prefs WHERE user_email = ? LIMIT 1");
    $st->execute([$email]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return true;
    if ((string)$r['all_disabled'] === '1' || (string)$r['mentions_only'] === '1') return false;
    return $r['task_due_soon'] === null || (string)$r['task_due_soon'] !== '0';
}
function alertEmailHtml(string $name, string $headline, string $lead, string $title, array $rows, string $tipColor): string {
    $trs = '';
    foreach ($rows as [$l, $v]) $trs .= '<tr><td style="padding:9px 14px;font-size:12px;color:#6b7280;font-weight:600">' . $l . '</td><td style="padding:9px 14px;font-size:13px;font-weight:700;color:#111827;text-align:right">' . htmlspecialchars($v) . '</td></tr>';
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"/></head><body style="margin:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,sans-serif"><table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px"><tr><td align="center"><table width="100%" style="max-width:520px;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb"><tr><td style="background:#d90b2c;padding:22px;text-align:center;color:#fff;font-size:20px;font-weight:800">SocialFlow</td></tr><tr><td style="padding:28px">'
        . '<h2 style="margin:0 0 8px;font-size:20px;color:#111827">' . $headline . '</h2>'
        . '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#4b5563">Hi <strong>' . htmlspecialchars($name) . '</strong>, ' . $lead . '</p>'
        . '<div style="background:#f9fafb;border-left:4px solid ' . $tipColor . ';padding:12px 16px;border-radius:0 10px 10px 0;margin:12px 0"><p style="margin:0;font-size:15px;font-weight:800;color:#111827">' . htmlspecialchars($title) . '</p></div>'
        . '<table width="100%" style="border:1px solid #e5e7eb;border-radius:10px;margin:14px 0">' . $trs . '</table>'
        . '<div style="text-align:center;margin:20px 0 4px"><a href="https://socialflow.admepro.com" style="display:inline-block;background:#d90b2c;color:#fff;text-decoration:none;padding:12px 28px;border-radius:10px;font-weight:700;font-size:14px">Open my timeline</a></div>'
        . '</td></tr></table></td></tr></table></body></html>';
}
function alertSend(string $to, string $subject, string $html): bool {
    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(["from" => "SocialFlow <noreply@admepro.com>", "to" => [$to], "subject" => $subject, "html" => $html]),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . RESEND_API_KEY, "Content-Type: application/json"]]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
$fmt = fn(int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
$sentAlerts = 0;
try {
    $claim = $pdo->prepare("INSERT IGNORE INTO task_alert_log (id, post_id, user_email, kind, alert_date) VALUES (UUID(), ?, ?, ?, ?)");
    foreach ($placed as $pid => $slot) {
        $p = $slot['post']; $m = $slot['member'];
        $dueMins = (int)$p['_due'];
        $kinds = [];
        if ($nowMins >= $slot['start'] && $nowMins < $slot['end']) $kinds[] = 'start';
        if ($nowMins >= $dueMins - 5 && $nowMins < $dueMins) $kinds[] = 'due5';
        foreach ($kinds as $kind) {
            if (!alertPrefsAllow($pdo, $m['email'])) continue;
            $claim->execute([$pid, $m['email'], $kind, $today]);
            if ($claim->rowCount() === 0) continue; // already sent today
            $rows = [['Client', $p['client_name'] ?? '—'], ['Your slot', $fmt($slot['start']) . ' – ' . $fmt($slot['end'])], ['Due time', $fmt($dueMins)]];
            if ($kind === 'start') {
                $ok = alertSend($m['email'], '[SocialFlow] Your task starts now: ' . $p['title'],
                    alertEmailHtml($m['name'], 'Your task is starting now', 'your scheduled task has started. Your timer is running — focus on it and deliver by the due time.', $p['title'], $rows, '#16a34a'));
            } else {
                $ok = alertSend($m['email'], '[SocialFlow] 5 minutes left: ' . $p['title'],
                    alertEmailHtml($m['name'], '⏰ 5 minutes left', !empty($p['is_routine']) ? 'this routine task is due in 5 minutes. Tick the last points and close it with your end note now — closing it on time improves your score.' : 'this task is due in 5 minutes. Deliver it and move it to the next stage now — closing it on time improves your score.', $p['title'], $rows, '#f59e0b'));
            }
            if ($ok) $sentAlerts++;
            $dbg("alert $kind -> {$m['email']} ({$p['title']}): " . ($ok ? 'sent' : 'FAILED'));
        }
    }
} catch (Throwable $e) { error_log('[auto-timer-cron] alerts: ' . $e->getMessage()); $dbg('alerts error: ' . $e->getMessage()); }
echo json_encode(['started' => $started, 'paused' => $paused, 'alerts' => $sentAlerts, 'at' => $now->format('H:i')]) . "\n";
