<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// READ-ONLY. Answers two questions from your own data:
//   1) Which scheduled posts are overdue and WHY the auto-publisher did not post them.
//   2) Which posts were published more than once (counted from the auto-publish log).
//   php publish-diagnose.php            both parts
//   php publish-diagnose.php slvr       only posts/logs whose client name contains "slvr"
// Nothing is changed.
// ================================================================
require_once __DIR__ . '/config.php';
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Cairo');
$only = strtolower(trim($argv[1] ?? ''));
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTime();
echo "Server time: " . $now->format('Y-m-d H:i:s') . "\nAUTO_PUBLISH_ENABLED: " . ((defined('AUTO_PUBLISH_ENABLED') && AUTO_PUBLISH_ENABLED) ? 'on' : 'OFF (nothing publishes automatically!)') . "\n";

// ── 1) Overdue scheduled posts ─────────────────────────────────
echo "\n=== 1) Posts in 'scheduled' whose time has passed, and why they are still waiting ===\n";
$rows = $pdo->query("SELECT p.*, ci.auto_publish_enabled
    FROM posts p LEFT JOIN client_intelligence ci ON ci.client_id = p.client_id WHERE p.stage = 'scheduled' ORDER BY p.scheduled_date, p.scheduled_time")->fetchAll(PDO::FETCH_ASSOC);
$n = 0;
foreach ($rows as $p) {
    if ($only !== '' && stripos((string)$p['client_name'], $only) === false) continue;
    $d = trim((string)$p['scheduled_date']); $t = trim((string)$p['scheduled_time']) ?: '09:00';
    $noDate = ($d === '');
    try { $at = new DateTime(($d === '' ? date('Y-m-d') : $d) . " $t"); } catch (Throwable $e) { $at = null; }
    if (!$at || ($at > $now && !$noDate)) continue;
    $n++;
    $why = [];
    if ($noDate) $why[] = 'NO SCHEDULED DATE on this post, so the auto-publisher skips it. Open the post, set the date and time, or publish it manually.';
    if ($p['task_type'] === 'grid_layout') $why[] = 'task type is grid_layout (design only, never published)';
    if ($p['auto_publish_enabled'] !== null && (int)$p['auto_publish_enabled'] === 0) $why[] = "this CLIENT has auto-publishing switched OFF (Settings > Scheduling > Auto Publishing), so it waits for a manual Publish Now";
    if ((int)$p['publish_attempts'] >= 3) $why[] = 'gave up after ' . (int)$p['publish_attempts'] . ' failed attempts';
    $all = json_decode((string)$p['platforms'], true) ?: [];
    if (!$all && $p['platform']) $all = [strtolower(trim($p['platform']))];
    $done = json_decode((string)$p['published_platforms'], true) ?: [];
    $valid = ['facebook', 'instagram', 'linkedin', 'tiktok', 'twitter'];
    if (!$all) $why[] = 'NO PLATFORM is selected on this post, so it can never be published automatically. Open the post and choose where it should go.';
    foreach ($all as $pl) {
        $pl = strtolower(trim($pl));
        if (in_array($pl, $done, true)) continue;
        if (!in_array($pl, $valid, true)) { $why[] = "platform \"{$pl}\" is not supported by the auto-publisher"; continue; }
        $q = $pdo->prepare("SELECT id, status, credentials FROM integrations WHERE app_key = ? AND client_id = ? ORDER BY (status='active') DESC LIMIT 1"); $q->execute([$pl, $p['client_id']]);
        $ig = $q->fetch(PDO::FETCH_ASSOC);
        if (!$ig) { $q = $pdo->prepare("SELECT id, status, credentials FROM integrations WHERE app_key = ? AND (client_id IS NULL OR client_id = '') ORDER BY (status='active') DESC LIMIT 1"); $q->execute([$pl]); $ig = $q->fetch(PDO::FETCH_ASSOC); }
        if (!$ig) $why[] = "no {$pl} account is connected for this client";
        elseif ($ig['status'] !== 'active') $why[] = "the {$pl} connection is \"{$ig['status']}\" (reconnect it in Integrations)";
        else { $c = json_decode((string)$ig['credentials'], true) ?: []; if (empty($c['access_token']) || ($pl !== 'tiktok' && empty($c['page_id']))) $why[] = "the {$pl} connection has no token / page id"; }
    }
    $pend = json_decode((string)($p['publish_pending'] ?? ''), true) ?: [];
    if (!empty($pend['instagram']['container_id'])) { $mins = round((time() - (int)($pend['instagram']['started'] ?? time())) / 60); $why = array_filter($why, fn($w) => strpos($w, 'nothing wrong') === false); $why[] = "WAITING for Instagram to finish processing the reel (started {$mins} min ago). This is normal for big videos: it is checked again every run."; }
    if ($p['publish_error']) $why[] = 'last error: ' . mb_substr($p['publish_error'], 0, 160);
    if (!$why) $why[] = 'nothing wrong found, it should publish on the next run (is the cron running?)';
    echo "- [{$p['client_name']}] \"{$p['title']}\"  due {$d} {$t}  platforms: " . implode('+', $all) . "  done: " . ($done ? implode('+', $done) : 'none') . "\n    → " . implode("\n    → ", $why) . "\n";
}
if (!$n) echo "(no overdue scheduled posts)\n";

// ── 2) Posts published more than once ─────────────────────────
echo "\n=== 2) Posts with MORE THAN ONE successful auto-publish (last 30 days) ===\n";
$logs = $pdo->query("SELECT payload_summary, COUNT(*) c, MIN(created_at) first_at, MAX(created_at) last_at FROM integration_logs
    WHERE triggered_by = 'auto-publish-cron' AND status = 'success' AND payload_summary LIKE 'Auto-publish post %' AND created_at >= NOW() - INTERVAL 30 DAY
    GROUP BY payload_summary HAVING c > 1 ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
$g = 0; $pt = $pdo->prepare("SELECT title, client_name FROM posts WHERE id = ?");
foreach ($logs as $l) {
    if (!preg_match('/post (\S+) \((\w+)\)/', $l['payload_summary'], $m)) continue;
    $pt->execute([$m[1]]); $post = $pt->fetch(PDO::FETCH_ASSOC) ?: ['title' => '(deleted)', 'client_name' => '?'];
    if ($only !== '' && stripos((string)$post['client_name'], $only) === false) continue;
    $g++;
    echo "- [{$post['client_name']}] \"{$post['title']}\" on {$m[2]}: published {$l['c']} times, first {$l['first_at']}, last {$l['last_at']}\n";
}
if (!$g) echo "(none found in the log)\n";
echo "\nTip: repeated times a few minutes apart = overlapping cron runs (fixed by the lock added with this script).\n";
