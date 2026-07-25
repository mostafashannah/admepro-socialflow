<?php
// ================================================================
// SocialFlow — Mai, AI Account Executive: runs once a day per client.
//
// Four fixed jobs, purely internal (never visible to clients):
//   1. Posting-cadence check — compares actual posts published in the last
//      7 days against the client's configured posting_frequency
//      (client_intelligence.posting_frequency, posts/week). If behind,
//      notifies the account manager + all admins.
//   1b. Scheduled-pipeline runway check — if the client's queue of already-
//      scheduled posts runs out within the next 10 working days (or is
//      empty), notifies the account manager + all admins. Re-alerts every
//      day this keeps running until new scheduled posts push the runway
//      back out past 10 working days.
//   2. Daily performance analysis — reads recent published posts (with
//      their insight_* metrics) + client_intelligence, asks Claude for a
//      short internal-only summary, saved into that client's memory.
//   3. Memory curation — reviews the client's existing memory entries
//      together with recent contact reports and assigns each a priority
//      (1-5), so the app can show the most important facts about a
//      client first instead of just insertion order.
//
// Suggested cron: 0 6 * * * php /var/www/socialflow/mai-daily-report-cron.php >> /var/www/socialflow/mai-daily-report.log 2>&1
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // reuses callClaude() + sendWhatsAppReply()

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function notify(PDO $pdo, string $email, string $title, string $message, string $type, ?string $linkId, ?string $linkType) {
    $stmt = $pdo->prepare("INSERT INTO notifications (id, recipient_email, title, message, type, is_read, link_id, link_type) VALUES (UUID(), :email, :title, :msg, :type, 0, :lid, :ltype)");
    $stmt->execute([':email' => $email, ':title' => $title, ':msg' => $message, ':type' => $type, ':lid' => $linkId, ':ltype' => $linkType]);
}

// Every other AI agent's actions land in activity_logs (tagged
// [agent:<id>], read by AgentProfilePage) via the JS-side agentAI()/
// logActivity() helpers — this cron runs entirely server-side with no
// browser involved, so without this call Mai's whole daily routine was
// invisible on her profile page even while she was actively sending real
// WhatsApp alerts/reports out.
function logMaiActivity(PDO $pdo, string $action, string $details, string $status = 'success') {
    try {
        $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'ai_agent', :details, :status, 'cron')")
            ->execute([':action' => $action, ':details' => '[agent:account_executive] ' . $details, ':status' => $status]);
    } catch (Throwable $e) { /* best-effort — never block the actual cron job over logging */ }
}

// The recipient set every one of Mai's per-client alerts/reports shares:
// that client's own account manager (only, not every AM) plus every admin,
// deduped by email.
function clientAlertRecipients(PDO $pdo, array $client, array $admins): array {
    $recipients = [];
    if (!empty($client['account_manager_id'])) {
        $am = $pdo->prepare("SELECT email, whatsapp_number FROM team_members WHERE id = :id");
        $am->execute([':id' => $client['account_manager_id']]);
        if ($row = $am->fetch(PDO::FETCH_ASSOC)) $recipients[] = $row;
    }
    foreach ($admins as $a) $recipients[] = $a;
    $seen = []; $out = [];
    foreach ($recipients as $r) {
        if (empty($r['email']) || isset($seen[$r['email']])) continue;
        $seen[$r['email']] = true;
        $out[] = $r;
    }
    return $out;
}

// Counts working days (Sun-Thu; matches the app's own addWorkingDays() in
// app.jsx, which treats Fri(5)/Sat(6) as the weekend) strictly between two
// dates. Returns 0 if $to is today or in the past.
function workingDaysBetween(DateTime $from, DateTime $to): int {
    if ($to <= $from) return 0;
    $count = 0;
    $cursor = clone $from;
    while ($cursor < $to) {
        $cursor->modify('+1 day');
        $dow = (int) $cursor->format('w'); // 0=Sun .. 6=Sat
        if ($dow !== 5 && $dow !== 6) $count++;
    }
    return $count;
}

$clients = $pdo->query("SELECT id, name, account_manager_id FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$admins = $pdo->query("SELECT email, whatsapp_number FROM team_members WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
$summary = ['clients_checked' => count($clients), 'cadence_alerts' => 0, 'pipeline_alerts' => 0, 'reports_written' => 0, 'memory_curated' => 0, 'errors' => []];

// Daily performance reports are collected per recipient across all their
// clients and sent as ONE combined WhatsApp message at the end of the run,
// instead of one message per client — an admin managing 6 accounts was
// getting 6 separate WhatsApp texts every morning. Cadence/pipeline alerts
// stay per-client (they're time-sensitive, one-off issues, not a routine
// digest) and are unaffected by this.
$dailyReportBuffer = []; // email => ['whatsapp_number'=>string, 'sections'=>[client_name => text]]

foreach ($clients as $client) {
    $clientId = $client['id'];
    $clientName = $client['name'];
    try {
        // ── 1. Posting-cadence check ──────────────────────────────
        $intel = $pdo->prepare("SELECT posting_frequency FROM client_intelligence WHERE client_id = :cid LIMIT 1");
        $intel->execute([':cid' => $clientId]);
        $expectedPerWeek = (float) ($intel->fetchColumn() ?: 3);

        $recent = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE client_id = :cid AND stage = 'published' AND published_at >= (NOW() - INTERVAL 7 DAY)");
        $recent->execute([':cid' => $clientId]);
        $actualLast7 = (int) $recent->fetchColumn();

        $lastPub = $pdo->prepare("SELECT MAX(published_at) FROM posts WHERE client_id = :cid AND stage = 'published'");
        $lastPub->execute([':cid' => $clientId]);
        $lastPublishedAt = $lastPub->fetchColumn();

        if ($expectedPerWeek > 0 && $actualLast7 < $expectedPerWeek) {
            $daysSince = $lastPublishedAt ? floor((time() - strtotime($lastPublishedAt)) / 86400) : null;
            $msg = "{$clientName} is behind its posting schedule — {$actualLast7} published in the last 7 days vs a target of {$expectedPerWeek}/week."
                . ($daysSince !== null ? " Last post was {$daysSince} day(s) ago." : " No posts published yet.");
            $waMsg = "Hey, it's Mai 👋 Just checked {$clientName}'s posting and we're a bit behind — {$actualLast7} published in the last 7 days vs the {$expectedPerWeek}/week we aim for."
                . ($daysSince !== null ? " Last post went out {$daysSince} day(s) ago." : " Nothing's gone out yet.")
                . " Can we get something scheduled soon?";
            foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                notify($pdo, $r['email'], "Posting behind schedule — {$clientName}", $msg, 'performance_alert', $clientId, 'client');
                if (!empty($r['whatsapp_number'])) sendWhatsAppReply($r['whatsapp_number'], $waMsg);
            }
            logMaiActivity($pdo, "Posting-cadence alert — {$clientName}", $msg);
            $summary['cadence_alerts']++;
        }

        // ── 1b. Scheduled-pipeline runway check ───────────────────
        // How many working days of already-scheduled posts does this client
        // still have queued up? If the last scheduled post runs out within
        // the next 10 working days (or nothing is scheduled at all), alert
        // the account manager + admins — and keep alerting every day this
        // cron runs until fresh scheduled posts push that runway back out.
        $lastScheduled = $pdo->prepare("SELECT MAX(scheduled_date) FROM posts WHERE client_id = :cid AND stage = 'scheduled' AND scheduled_date IS NOT NULL");
        $lastScheduled->execute([':cid' => $clientId]);
        $lastScheduledDate = $lastScheduled->fetchColumn();
        $today = new DateTime('today');
        $runwayDays = $lastScheduledDate ? workingDaysBetween($today, new DateTime($lastScheduledDate)) : 0;

        if ($runwayDays < 10) {
            $msg = $lastScheduledDate
                ? "{$clientName}'s scheduled-post pipeline runs out in {$runwayDays} working day(s) (last scheduled post: {$lastScheduledDate}). Add more scheduled posts to keep at least 10 working days of runway."
                : "{$clientName} has no scheduled posts queued up at all. Add scheduled posts to build a pipeline.";
            $waMsg = $lastScheduledDate
                ? "Hey, it's Mai 👋 Heads up — {$clientName}'s scheduled posts run out in {$runwayDays} working day(s) (last one's set for {$lastScheduledDate}). Let's line up more so we don't lose the runway. Happy to help pull together ideas if that'd save you time."
                : "Hey, it's Mai 👋 {$clientName} doesn't have anything scheduled right now — the pipeline's empty. Let's get some posts queued up. Happy to help pull together ideas if that'd save you time.";
            foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                notify($pdo, $r['email'], "Scheduled posts running low — {$clientName}", $msg, 'pipeline_alert', $clientId, 'client');
                if (!empty($r['whatsapp_number'])) sendWhatsAppReply($r['whatsapp_number'], $waMsg);
            }
            logMaiActivity($pdo, "Pipeline-runway alert — {$clientName}", $msg);
            $summary['pipeline_alerts']++;
        }

        // ── 2. Daily performance analysis ─────────────────────────
        $posts = $pdo->prepare(
            "SELECT title, platform, post_type, published_at, insight_likes, insight_comments, insight_shares, insight_reach
             FROM posts WHERE client_id = :cid AND stage = 'published' AND published_at >= (NOW() - INTERVAL 14 DAY)
             ORDER BY published_at DESC LIMIT 30"
        );
        $posts->execute([':cid' => $clientId]);
        $postRows = $posts->fetchAll(PDO::FETCH_ASSOC);

        if ($postRows) {
            $postLines = array_map(function($p) {
                return "- [{$p['platform']}/{$p['post_type']}] \"{$p['title']}\" on " . substr((string)$p['published_at'], 0, 10)
                    . " — likes:" . ($p['insight_likes'] ?? '?') . " comments:" . ($p['insight_comments'] ?? '?')
                    . " shares:" . ($p['insight_shares'] ?? '?') . " reach:" . ($p['insight_reach'] ?? '?');
            }, $postRows);
            $prompt = "You are Mai, the agency's internal AI Account Executive. Write a short (120-180 word) internal-only daily "
                . "performance analysis for the client \"{$clientName}\", based on their last 14 days of published posts below. "
                . "Cover: what's working, what's underperforming, and one concrete recommendation for the team. This is NEVER shown "
                . "to the client — be direct and specific, not diplomatic filler.\n\n" . implode("\n", $postLines);
            [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 500, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            $analysis = '';
            if ($status >= 200 && $status < 300) {
                foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $analysis .= $block['text']; }
            }
            if (trim($analysis) !== '') {
                $today = date('Y-m-d');
                $existing = $pdo->prepare("SELECT id FROM client_memory WHERE client_id = :cid AND `key` = :k");
                $existing->execute([':cid' => $clientId, ':k' => "mai_daily_report_{$today}"]);
                if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("UPDATE client_memory SET value = :v WHERE id = :id")->execute([':v' => trim($analysis), ':id' => $row['id']]);
                } else {
                    $pdo->prepare("INSERT INTO client_memory (id, client_id, client_name, `key`, value, type) VALUES (UUID(), :cid, :cname, :k, :v, 'mai_daily_report')")
                        ->execute([':cid' => $clientId, ':cname' => $clientName, ':k' => "mai_daily_report_{$today}", ':v' => trim($analysis)]);
                }
                $summary['reports_written']++;

                // In-app notification stays per-client (each one is its own
                // real record, that's fine); the WhatsApp send is buffered
                // instead and combined into one message per recipient at the
                // end of the run — see $dailyReportBuffer above.
                foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                    notify($pdo, $r['email'], "Daily report — {$clientName}", trim($analysis), 'daily_report', $clientId, 'client');
                    if (!empty($r['whatsapp_number'])) {
                        if (!isset($dailyReportBuffer[$r['email']])) $dailyReportBuffer[$r['email']] = ['whatsapp_number' => $r['whatsapp_number'], 'sections' => []];
                        $dailyReportBuffer[$r['email']]['sections'][$clientName] = trim($analysis);
                    }
                }
                logMaiActivity($pdo, "Daily performance report — {$clientName}", trim($analysis));
            }
        }

        // ── 3. Memory curation — prioritize existing memory ───────
        $memRows = $pdo->prepare("SELECT id, `key`, value FROM client_memory WHERE client_id = :cid AND type != 'mai_daily_report' ORDER BY updated_at DESC LIMIT 40");
        $memRows->execute([':cid' => $clientId]);
        $memRows = $memRows->fetchAll(PDO::FETCH_ASSOC);

        if (count($memRows) >= 3) {
            $contactReports = $pdo->prepare("SELECT summary, key_points FROM contact_reports WHERE client_id = :cid ORDER BY created_at DESC LIMIT 5");
            $contactReports->execute([':cid' => $clientId]);
            $crLines = array_map(fn($r) => "- " . ($r['summary'] ?? '') . ($r['key_points'] ? " | " . $r['key_points'] : ''), $contactReports->fetchAll(PDO::FETCH_ASSOC));

            $memLines = array_map(fn($m) => "{$m['key']}: {$m['value']}", $memRows);
            $prompt = "You are Mai, reviewing everything known about the client \"{$clientName}\" to decide what matters most for "
                . "the team to see first. Below are the client's saved memory facts, and their most recent contact reports "
                . "(meetings/calls) for context on what's currently important to them.\n\nMEMORY FACTS:\n" . implode("\n", $memLines)
                . (count($crLines) ? "\n\nRECENT CONTACT REPORTS:\n" . implode("\n", $crLines) : "")
                . "\n\nReturn ONLY a JSON array scoring EVERY memory fact above by importance right now, 1 (low) to 5 (critical — "
                . "e.g. an explicit brand rule, a recent urgent request, a hard constraint) — format: "
                . "[{\"key\":\"exact_key_from_above\",\"priority\":1-5}]. Nothing else, no explanation.";
            [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 800, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            $text = '';
            if ($status >= 200 && $status < 300) {
                foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $text .= $block['text']; }
            }
            if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
                $scores = json_decode($m[0], true);
                if (is_array($scores)) {
                    $upd = $pdo->prepare("UPDATE client_memory SET priority = :p WHERE client_id = :cid AND `key` = :k");
                    foreach ($scores as $s) {
                        if (empty($s['key'])) continue;
                        $p = max(1, min(5, (int) ($s['priority'] ?? 1)));
                        $upd->execute([':p' => $p, ':cid' => $clientId, ':k' => $s['key']]);
                    }
                    logMaiActivity($pdo, "Memory curation — {$clientName}", "Re-scored " . count($scores) . " memory fact(s) by current importance.");
                    $summary['memory_curated']++;
                }
            }
        }
    } catch (Throwable $e) {
        $summary['errors'][] = "{$clientName}: " . $e->getMessage();
        error_log("[mai-daily-report-cron] {$clientName}: " . $e->getMessage());
    }
}

// One combined WhatsApp message per recipient, covering every client they
// got a report for today (an admin sees all of them; an account manager
// only their own clients — same scoping clientAlertRecipients already did
// per-message before).
foreach ($dailyReportBuffer as $email => $entry) {
    if (empty($entry['sections'])) continue;
    $intro = count($entry['sections']) > 1
        ? "Hey, it's Mai 👋 Here's today's read across your accounts:"
        : "Hey, it's Mai 👋 Here's today's read on " . array_key_first($entry['sections']) . ":";
    $body = implode("\n\n", array_map(
        fn($name, $text) => count($entry['sections']) > 1 ? "*{$name}*\n{$text}" : $text,
        array_keys($entry['sections']), array_values($entry['sections'])
    ));
    $msg = $intro . "\n\n" . $body . "\n\nLet me know if you want a hand with anything on these accounts.";
    sendWhatsAppReply($entry['whatsapp_number'], $msg);
}

header('Content-Type: application/json');
echo json_encode($summary);
