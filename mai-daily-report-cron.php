<?php
// ================================================================
// SocialFlow — Mai, AI Account Executive: runs once a day per client.
//
// Four fixed jobs, purely internal (never visible to clients):
//   1. Posting-cadence check — compares actual posts published in the last
//      7 days against the client's configured posting_frequency
//      (client_intelligence.posting_frequency, posts/week).
//   1b. Scheduled-pipeline runway check — flags if the client's queue of
//      already-scheduled posts runs out within the next 10 working days
//      (or is empty). Re-flags every day this keeps running until new
//      scheduled posts push the runway back out past 10 working days.
//   2. Daily performance analysis — reads recent published posts (with
//      their insight_* metrics) + client_intelligence, asks Claude for a
//      short internal-only summary, saved into that client's memory.
//   3. Memory curation — reviews the client's existing memory entries
//      together with recent contact reports and assigns each a priority
//      (1-5), so the app can show the most important facts about a
//      client first instead of just insertion order.
//
// Every finding still gets a full, permanent in-app notification (so
// "check SocialFlow for details" is always literally true) — but the
// WhatsApp side is different: instead of a wall of near-identical
// templated messages (one per client per issue), every recipient gets
// exactly ONE message per run, covering every client they're scoped to.
// Claude writes that single message in Mai's voice from the raw structured
// findings below (not a fill-in-the-blank PHP string), so wording varies
// run to run instead of reading like a bot template, warning emoji are
// used only for a genuine problem (behind on cadence / pipeline running
// dry), and it stays short — full detail lives in SocialFlow notifications,
// which the message points to.
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

// The recipient set every one of Mai's per-client findings shares: that
// client's own account manager (only, not every AM) plus every admin,
// deduped by email.
function clientAlertRecipients(PDO $pdo, array $client, array $admins): array {
    $recipients = [];
    if (!empty($client['account_manager_id'])) {
        $amIds = json_decode($client['account_manager_id'], true);
        if (!is_array($amIds)) $amIds = [$client['account_manager_id']];
        foreach ($amIds as $amId) {
            $am = $pdo->prepare("SELECT email, whatsapp_number FROM team_members WHERE id = :id");
            $am->execute([':id' => $amId]);
            if ($row = $am->fetch(PDO::FETCH_ASSOC)) $recipients[] = $row;
        }
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
$admins = $pdo->query("SELECT email, whatsapp_number FROM team_members WHERE role IN ('admin','account_manager') AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$summary = ['clients_checked' => count($clients), 'cadence_alerts' => 0, 'pipeline_alerts' => 0, 'reports_written' => 0, 'memory_curated' => 0, 'errors' => []];

// Raw structured findings per recipient — no pre-written prose. One Claude
// call per recipient at the end of the run turns this into their single
// WhatsApp message, in Mai's voice.
$recipientFindings = []; // email => ['whatsapp_number'=>string, 'clients'=>[clientName => {cadence, pipeline, report}]]

function addFinding(array &$recipientFindings, PDO $pdo, array $client, array $admins, string $clientName, string $field, $value) {
    foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
        if (empty($r['whatsapp_number'])) continue;
        if (!isset($recipientFindings[$r['email']])) $recipientFindings[$r['email']] = ['whatsapp_number' => $r['whatsapp_number'], 'clients' => []];
        if (!isset($recipientFindings[$r['email']]['clients'][$clientName])) $recipientFindings[$r['email']]['clients'][$clientName] = [];
        $recipientFindings[$r['email']]['clients'][$clientName][$field] = $value;
    }
}

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

        $cadenceBehind = $expectedPerWeek > 0 && $actualLast7 < $expectedPerWeek;
        if ($cadenceBehind) {
            $daysSince = $lastPublishedAt ? floor((time() - strtotime($lastPublishedAt)) / 86400) : null;
            $msg = "{$clientName} is behind its posting schedule — {$actualLast7} published in the last 7 days vs a target of {$expectedPerWeek}/week."
                . ($daysSince !== null ? " Last post was {$daysSince} day(s) ago." : " No posts published yet.");
            foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                notify($pdo, $r['email'], "Posting behind schedule — {$clientName}", $msg, 'performance_alert', $clientId, 'client');
            }
            addFinding($recipientFindings, $pdo, $client, $admins, $clientName, 'cadence', "BEHIND: {$actualLast7}/{$expectedPerWeek} per week published in last 7 days" . ($daysSince !== null ? ", last post {$daysSince}d ago" : ", nothing published yet"));
            logMaiActivity($pdo, "Posting-cadence alert — {$clientName}", $msg);
            $summary['cadence_alerts']++;
        }

        // ── 1b. Scheduled-pipeline runway check ───────────────────
        // How many working days of already-scheduled posts does this client
        // still have queued up? If the last scheduled post runs out within
        // the next 10 working days (or nothing is scheduled at all), flag it
        // — and keep flagging every day this cron runs until fresh scheduled
        // posts push that runway back out.
        $lastScheduled = $pdo->prepare("SELECT MAX(scheduled_date) FROM posts WHERE client_id = :cid AND stage = 'scheduled' AND scheduled_date IS NOT NULL");
        $lastScheduled->execute([':cid' => $clientId]);
        $lastScheduledDate = $lastScheduled->fetchColumn();
        $today = new DateTime('today');
        $runwayDays = $lastScheduledDate ? workingDaysBetween($today, new DateTime($lastScheduledDate)) : 0;

        $pipelineLow = $runwayDays < 10;
        if ($pipelineLow) {
            $msg = $lastScheduledDate
                ? "{$clientName}'s scheduled-post pipeline runs out in {$runwayDays} working day(s) (last scheduled post: {$lastScheduledDate}). Add more scheduled posts to keep at least 10 working days of runway."
                : "{$clientName} has no scheduled posts queued up at all. Add scheduled posts to build a pipeline.";
            foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                notify($pdo, $r['email'], "Scheduled posts running low — {$clientName}", $msg, 'pipeline_alert', $clientId, 'client');
            }
            addFinding($recipientFindings, $pdo, $client, $admins, $clientName, 'pipeline', $lastScheduledDate ? "LOW: only {$runwayDays} working days of scheduled posts left" : "EMPTY: nothing scheduled at all");
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
            $prompt = "You are Mai, the agency's internal AI Account Executive, analyzing the client \"{$clientName}\"'s last 14 days "
                . "of published posts below. This is NEVER shown to the client — be direct and specific, not diplomatic filler.\n\n"
                . implode("\n", $postLines)
                . "\n\nReturn ONLY valid JSON (no markdown): {\"analysis\":\"120-180 word internal analysis covering what's working, "
                . "what's underperforming, and one concrete recommendation\",\"takeaway\":\"ONE short punchy sentence, under 15 words, "
                . "no jargon — this exact sentence gets texted to a teammate on WhatsApp, so it must stand alone and make sense with zero "
                . "other context\"}";
            [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 600, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            $raw = '';
            if ($status >= 200 && $status < 300) {
                foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $raw .= $block['text']; }
            }
            $analysis = ''; $takeaway = '';
            if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) { $analysis = trim($parsed['analysis'] ?? ''); $takeaway = trim($parsed['takeaway'] ?? ''); }
            }
            if ($analysis !== '') {
                $todayStr = date('Y-m-d');
                $existing = $pdo->prepare("SELECT id FROM client_memory WHERE client_id = :cid AND `key` = :k");
                $existing->execute([':cid' => $clientId, ':k' => "mai_daily_report_{$todayStr}"]);
                if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("UPDATE client_memory SET value = :v WHERE id = :id")->execute([':v' => $analysis, ':id' => $row['id']]);
                } else {
                    $pdo->prepare("INSERT INTO client_memory (id, client_id, client_name, `key`, value, type) VALUES (UUID(), :cid, :cname, :k, :v, 'mai_daily_report')")
                        ->execute([':cid' => $clientId, ':cname' => $clientName, ':k' => "mai_daily_report_{$todayStr}", ':v' => $analysis]);
                }
                $summary['reports_written']++;

                foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
                    notify($pdo, $r['email'], "Daily report — {$clientName}", $analysis, 'daily_report', $clientId, 'client');
                }
                // Only the short takeaway feeds the WhatsApp message — the
                // full analysis lives in the notification/memory, never
                // repeated verbatim in the text that gets sent.
                if ($takeaway !== '') addFinding($recipientFindings, $pdo, $client, $admins, $clientName, 'report', mb_substr($takeaway, 0, 160));
                logMaiActivity($pdo, "Daily performance report — {$clientName}", $analysis);
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

// ── One WhatsApp message per recipient, written by Claude in Mai's voice ──
// Real problems (cadence behind / pipeline low-or-empty) get flagged with
// ⚠️; clients with nothing wrong get at most a quick mention, not a full
// writeup. Always short, always closes by pointing to SocialFlow
// notifications for the full detail and offering to elaborate if asked —
// never a fixed template, so the wording genuinely varies run to run.
$maiWaSystem = "You are Mai, the agency's AI Account Executive, sending a WhatsApp update to a teammate. Your character: "
    . "analytical and decisive, warm but not chatty, no corporate filler. You never open with the exact same line twice — "
    . "vary your phrasing/greeting naturally like a real person texting, not a template.\n\n"
    . "HARD RULES — these are not suggestions, a long message defeats the entire point:\n"
    . "- STRICT LENGTH LIMIT: the ENTIRE message must be under 500 characters total, no exceptions. If you have many clients, that means "
    . "one short clause each, not a paragraph — group the fine ones into a single line rather than listing each individually.\n"
    . "- ONE message only. This is a WhatsApp ping, not an email or a report — nobody will read a wall of text, so being readable matters "
    . "more than being complete.\n"
    . "- Use ⚠️ ONLY for a client with a REAL problem below (cadence behind schedule, or pipeline low/empty). Never use it for a client that's fine.\n"
    . "- For clients with no problems, mention them briefly or in a single grouped line (e.g. \"X and Y are on track\") — never a paragraph per healthy client.\n"
    . "- NEVER repeat/paste full report text, numbers, or multiple sentences per client — one short clause per client, max.\n"
    . "- End with ONE short line pointing to SocialFlow notifications for full details and inviting them to ask you for more — not a full sentence per client repeating this.\n"
    . "- Never use markdown headers, '#', or bullet-point '-' lists — write like a real WhatsApp text (short lines/emoji are fine, formal lists/headers are not).";

error_log('[mai-debug] recipientFindings count: ' . count($recipientFindings) . ' — keys: ' . implode(', ', array_keys($recipientFindings)));
foreach ($recipientFindings as $email => $entry) {
    error_log('[mai-debug] processing recipient: ' . $email . ' clients: ' . implode(', ', array_keys($entry['clients'] ?? [])));
    if (empty($entry['clients'])) continue;
    $lines = [];
    foreach ($entry['clients'] as $name => $facts) {
        $parts = [];
        if (!empty($facts['cadence'])) $parts[] = "cadence: " . $facts['cadence'];
        if (!empty($facts['pipeline'])) $parts[] = "pipeline: " . $facts['pipeline'];
        if (!empty($facts['report'])) $parts[] = "today's read: " . $facts['report'];
        if (!$parts) $parts[] = "no issues, nothing new to flag";
        $lines[] = "{$name} — " . implode(" | ", $parts);
    }
    $userMsg = "Today's findings across your accounts:\n" . implode("\n", $lines) . "\n\nWrite the one WhatsApp message now.";
    [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 400, 'system' => $maiWaSystem, 'messages' => [['role' => 'user', 'content' => $userMsg]]]);
    $msg = '';
    if ($status >= 200 && $status < 300) {
        foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $msg .= $block['text']; }
    }
    $msg = trim($msg);
    // Belt-and-suspenders: the system prompt asks for under 500 characters,
    // but never trust a model's length compliance completely — a message
    // nobody will actually read defeats the entire point of this rewrite.
    // Cut at the last whole word before the limit rather than mid-word.
    if (mb_strlen($msg) > 550) {
        $cut = mb_substr($msg, 0, 500);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) $cut = mb_substr($cut, 0, $lastSpace);
        $msg = $cut . "… full details in SocialFlow notifications.";
    }
    if ($msg === '') {
        // Fallback if the AI call itself fails — still one message, still
        // short, just without her usual phrasing variety.
        $msg = "Mai here — quick account check: " . implode("; ", array_map(
            fn($n, $f) => $n . (!empty($f['cadence']) || !empty($f['pipeline']) ? " ⚠️" : " ✅"),
            array_keys($entry['clients']), array_values($entry['clients'])
        )) . ". Full details in SocialFlow notifications — ask me for more on any account.";
    }
    sendWhatsAppReply($entry['whatsapp_number'], $msg);
}

header('Content-Type: application/json');
echo json_encode($summary);
