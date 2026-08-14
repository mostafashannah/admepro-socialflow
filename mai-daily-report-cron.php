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
// deduped by email. `$admins` here MUST already be filtered to role='admin'
// only — passing a list that also contains account managers would silently
// fan every client's findings out to every AM instead of just the one
// actually assigned to that client (this was a real bug: the caller used
// to fetch role IN ('admin','account_manager') into a variable it then
// blindly merged into every client's recipient list).
function clientAlertRecipients(PDO $pdo, array $client, array $admins): array {
    $recipients = [];
    if (!empty($client['account_manager_id'])) {
        $amIds = json_decode($client['account_manager_id'], true);
        if (!is_array($amIds)) $amIds = [$client['account_manager_id']];
        foreach ($amIds as $amId) {
            $am = $pdo->prepare("SELECT email, whatsapp_number, name FROM team_members WHERE id = :id");
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
// Real admins ONLY — every account manager already gets their own clients'
// findings via clientAlertRecipients() reading client.account_manager_id;
// including AMs here too used to fan every client's alerts out to every
// AM regardless of assignment (see the comment on clientAlertRecipients).
$admins = $pdo->query("SELECT email, whatsapp_number, name FROM team_members WHERE role = 'admin' AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$summary = ['clients_checked' => count($clients), 'cadence_alerts' => 0, 'pipeline_alerts' => 0, 'reports_written' => 0, 'memory_curated' => 0, 'errors' => []];

// Raw structured findings per recipient — no pre-written prose. One Claude
// call per recipient at the end of the run turns this into their single
// WhatsApp message, in Mai's voice.
$recipientFindings = []; // email => ['whatsapp_number'=>string, 'clients'=>[clientName => {cadence, pipeline, report}]]

function addFinding(array &$recipientFindings, PDO $pdo, array $client, array $admins, string $clientName, string $field, $value) {
    foreach (clientAlertRecipients($pdo, $client, $admins) as $r) {
        if (empty($r['whatsapp_number'])) continue;
        if (!isset($recipientFindings[$r['email']])) $recipientFindings[$r['email']] = ['whatsapp_number' => $r['whatsapp_number'], 'name' => $r['name'] ?? '', 'clients' => []];
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

        // published_at is only stamped by the app's own publish flow
        // (auto-publish cron, the manual Publish button, or a stage change
        // that lands on Published) — a post marked published_at some other
        // way (direct DB edit, an older code path, demo/import data) can
        // sit at stage='published' with published_at still NULL. Filtering
        // on published_at alone then makes a client with real, recent
        // published content look like "0 published, nothing posted" —
        // exactly the false "behind schedule" flag this cron exists to
        // avoid. COALESCE to scheduled_date (when it's already passed) or
        // created_at as the best available stand-in for when it actually
        // went out.
        $recent = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE client_id = :cid AND stage = 'published' AND COALESCE(published_at, scheduled_date, created_at) >= (NOW() - INTERVAL 7 DAY)");
        $recent->execute([':cid' => $clientId]);
        $actualLast7 = (int) $recent->fetchColumn();

        $lastPub = $pdo->prepare("SELECT MAX(COALESCE(published_at, scheduled_date, created_at)) FROM posts WHERE client_id = :cid AND stage = 'published'");
        $lastPub->execute([':cid' => $clientId]);
        $lastPublishedAt = $lastPub->fetchColumn();

        $cadenceBehind = $expectedPerWeek > 0 && $actualLast7 < $expectedPerWeek;
        $daysSince = $lastPublishedAt ? floor((time() - strtotime($lastPublishedAt)) / 86400) : null;
        // Always record the raw numbers, even for a healthy account — the
        // WhatsApp writer used to only ever see concrete figures (X/Y this
        // week, last post Nd ago) for accounts that were flagged as
        // behind, so a fine account got reduced to a bare "on track" with
        // nothing to actually check it against. Real numbers on every
        // account daily also make it far easier to catch a stale
        // published_at bug (an account genuinely posted yesterday but the
        // report still calling it stale) at a glance instead of it hiding
        // behind a vague "no issues" line.
        addFinding($recipientFindings, $pdo, $client, $admins, $clientName, 'stats', "{$actualLast7}/{$expectedPerWeek} per week posted, last post " . ($daysSince !== null ? "{$daysSince}d ago" : "never"));
        if ($cadenceBehind) {
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

        $scheduledCountStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE client_id = :cid AND stage = 'scheduled'");
        $scheduledCountStmt->execute([':cid' => $clientId]);
        $scheduledCount = (int) $scheduledCountStmt->fetchColumn();
        // Recorded for every client, healthy or not — same reasoning as
        // 'stats' above: the WhatsApp writer needs the raw scheduled count
        // on hand for every account to build the two-line Published/
        // Scheduled format, not just the ones flagged as low.
        addFinding($recipientFindings, $pdo, $client, $admins, $clientName, 'scheduled_count', "{$scheduledCount} scheduled");

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
             FROM posts WHERE client_id = :cid AND stage = 'published' AND COALESCE(published_at, scheduled_date, created_at) >= (NOW() - INTERVAL 14 DAY)
             ORDER BY COALESCE(published_at, scheduled_date, created_at) DESC LIMIT 30"
        );
        $posts->execute([':cid' => $clientId]);
        $postRows = $posts->fetchAll(PDO::FETCH_ASSOC);

        // Brand/strategy context (uploaded ChatGPT chats, brand docs, AM
        // check-in facts, manual notes) — this used to be completely
        // invisible to the daily analysis, which only ever looked at raw
        // post insight numbers. Without it Mai can't actually reason about
        // WHY something is or isn't working, just report the numbers back.
        $memStmt = $pdo->prepare("SELECT `key`, value FROM client_memory WHERE client_id = :cid AND type != 'mai_daily_report' ORDER BY priority DESC, updated_at DESC LIMIT 10");
        $memStmt->execute([':cid' => $clientId]);
        $memRows = $memStmt->fetchAll(PDO::FETCH_ASSOC);
        $memBlock = $memRows ? "\n\nKNOWN BRAND/STRATEGY CONTEXT (from uploaded docs, check-ins, notes):\n" . implode("\n", array_map(fn($m) => "- {$m['key']}: {$m['value']}", $memRows)) : '';

        // Runs even with zero recent posts — a quiet account is itself
        // something Mai should be able to speak to (using cadence/pipeline
        // state + memory context), not just silently skipped, since
        // "analyze what's happening on every account daily" means every
        // account, not only the ones that happened to post recently.
        if ($postRows || $memRows || $cadenceBehind || $pipelineLow) {
            $postLines = $postRows ? array_map(function($p) {
                return "- [{$p['platform']}/{$p['post_type']}] \"{$p['title']}\" on " . substr((string)$p['published_at'], 0, 10)
                    . " — likes:" . ($p['insight_likes'] ?? '?') . " comments:" . ($p['insight_comments'] ?? '?')
                    . " shares:" . ($p['insight_shares'] ?? '?') . " reach:" . ($p['insight_reach'] ?? '?');
            }, $postRows) : ["(nothing published in the last 14 days)"];
            $prompt = "You are Mai, the agency's internal AI Account Executive, analyzing the client \"{$clientName}\"'s last 14 days "
                . "of published posts below. This is NEVER shown to the client — be direct and specific, not diplomatic filler.\n\n"
                . implode("\n", $postLines) . $memBlock
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

        // ── 4. Auto-refresh the Knowledge Profile ─────────────────
        // Same synthesis the in-app "Generate from existing posts &
        // memory" button does (summary/tone/content_preferences/keywords/
        // priorities/dos/donts/target_audience from EVERY data source
        // combined), but run automatically once a day per client instead
        // of requiring a manual click — so the profile actually stays
        // current with whatever's new (a fresh contact report, a newly
        // published post, a new memory fact, an uploaded doc) without
        // anyone remembering to regenerate it. Skipped entirely if there's
        // genuinely nothing to synthesize from (same guard the in-app
        // button now has) — an empty client shouldn't get a hallucinated
        // profile just because the cron ran.
        $memAll = $pdo->prepare("SELECT `key`, value FROM client_memory WHERE client_id = :cid ORDER BY priority DESC, updated_at DESC LIMIT 40");
        $memAll->execute([':cid' => $clientId]);
        $memAllLines = array_map(fn($m) => "- {$m['key']}: {$m['value']}", $memAll->fetchAll(PDO::FETCH_ASSOC));

        $crAll = $pdo->prepare("SELECT summary, key_points, action_items, created_by_name, created_at FROM contact_reports WHERE client_id = :cid ORDER BY created_at DESC LIMIT 10");
        $crAll->execute([':cid' => $clientId]);
        $crAllLines = array_map(function($r) {
            $parts = ["Meeting/Call with " . ($r['created_by_name'] ?: 'team') . " on " . substr((string)($r['created_at'] ?? ''), 0, 10)];
            if ($r['summary']) $parts[] = "Summary: {$r['summary']}";
            if ($r['key_points']) $parts[] = "Key points: {$r['key_points']}";
            if ($r['action_items']) $parts[] = "Action items: {$r['action_items']}";
            return implode("\n", $parts);
        }, $crAll->fetchAll(PDO::FETCH_ASSOC));

        $capStmt = $pdo->prepare("SELECT platform, caption FROM posts WHERE client_id = :cid AND stage = 'published' AND caption IS NOT NULL AND caption != '' ORDER BY published_at DESC LIMIT 20");
        $capStmt->execute([':cid' => $clientId]);
        $capLines = array_map(fn($p) => "[{$p['platform']}] " . mb_substr($p['caption'], 0, 300), $capStmt->fetchAll(PDO::FETCH_ASSOC));

        $docStmt = $pdo->prepare("SELECT content FROM client_documents WHERE client_id = :cid ORDER BY created_at DESC LIMIT 3");
        $docStmt->execute([':cid' => $clientId]);
        // Was capped at 2000 chars — harmless while uploads themselves were
        // capped at 8000, but documents are now stored in full (500K+
        // chars for a real ChatGPT export), so this fed the AI almost
        // nothing from the real upload.
        $docText = mb_substr(implode("\n\n", array_filter(array_map(fn($d) => $d['content'] ?? '', $docStmt->fetchAll(PDO::FETCH_ASSOC)))), 0, 700000);

        if ($memAllLines || $crAllLines || $capLines || $docText) {
            $kbPrompt = "You are a senior brand strategist. Analyze ALL available data for the client \"{$clientName}\" and produce a comprehensive, "
                . "accurate brand knowledge profile.\n\n=== MEMORY / SAVED BRAND FACTS ===\n" . ($memAllLines ? implode("\n", $memAllLines) : "None saved yet")
                . "\n\n=== CONTACT REPORTS (recent client meetings & calls) ===\n" . ($crAllLines ? implode("\n\n---\n\n", $crAllLines) : "None yet")
                . "\n\n=== PUBLISHED CAPTIONS (sample of real content) ===\n" . ($capLines ? implode("\n\n", $capLines) : "None available")
                . "\n\n=== UPLOADED DOCUMENTS ===\n" . ($docText ?: "None uploaded")
                . "\n\nBased on ALL of the above, return ONLY valid JSON with these exact keys:\n"
                . '{"summary":"3-4 sentence brand overview covering who they are, what they sell/offer, and their positioning","tone":"comma-separated tone descriptors","content_preferences":"what content formats/themes work for them","keywords":["5-10 brand keywords"],"priorities":["3-5 strategic content priorities"],"dos":["do this","and this"],"donts":["avoid this","never this"],"target_audience":"who they are targeting","general_info":"any contacts, locations/branches, addresses, phone numbers, hours, or other general company facts mentioned above — plain text, one fact per line. Empty string if none found."}';
            // 1000 was too tight for a full summary+tone+content_preferences+
            // keywords+priorities+dos+donts+target_audience response — it
            // regularly cut off mid-object, which the regex below correctly
            // refuses as invalid JSON (silently skipping the whole refresh).
            [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 1800, 'messages' => [['role' => 'user', 'content' => $kbPrompt]]]);
            $kbRaw = '';
            if ($status >= 200 && $status < 300) {
                foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $kbRaw .= $block['text']; }
            }
            if (preg_match('/\{[\s\S]*\}/', $kbRaw, $m)) {
                $kb = json_decode($m[0], true);
                if (is_array($kb)) {
                    $ckExisting = $pdo->prepare("SELECT id, version FROM client_knowledge WHERE client_id = :cid");
                    $ckExisting->execute([':cid' => $clientId]);
                    $ckRow = $ckExisting->fetch(PDO::FETCH_ASSOC);
                    $kbFields = [
                        'summary' => $kb['summary'] ?? '', 'tone' => $kb['tone'] ?? '',
                        'content_preferences' => $kb['content_preferences'] ?? '',
                        'keywords' => json_encode($kb['keywords'] ?? []), 'priorities' => json_encode($kb['priorities'] ?? []),
                        'dos' => implode("\n", $kb['dos'] ?? []), 'donts' => implode("\n", $kb['donts'] ?? []),
                        'target_audience' => $kb['target_audience'] ?? '',
                        'general_info' => $kb['general_info'] ?? '',
                        'last_analyzed' => date('Y-m-d H:i:s'), 'analyzed_by' => 'mai-daily-cron',
                    ];
                    if ($ckRow) {
                        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($kbFields)));
                        $pdo->prepare("UPDATE client_knowledge SET {$sets}, version = version + 1 WHERE id = :id")
                            ->execute([...$kbFields, 'id' => $ckRow['id']]);
                    } else {
                        $kbFields['id'] = bin2hex(random_bytes(16));
                        $kbFields['client_id'] = $clientId; $kbFields['client_name'] = $clientName; $kbFields['version'] = 1;
                        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($kbFields)));
                        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($kbFields)));
                        $pdo->prepare("INSERT INTO client_knowledge ({$cols}) VALUES ({$ph})")->execute($kbFields);
                    }
                    logMaiActivity($pdo, "Knowledge profile auto-refreshed — {$clientName}", "Regenerated from " . count($memAllLines) . " memory fact(s), " . count($crAllLines) . " contact report(s), " . count($capLines) . " caption(s).");
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
// Friday/Saturday, same weekend convention as workingDaysBetween() above —
// a report that opens with "Good morning" on a day nobody's actually
// working reads oddly. Computed once, used both in the greeting rule below
// and in the belt-and-suspenders fallback further down.
$todayDow = (int) date('w'); // 0=Sun .. 6=Sat
$isWeekend = $todayDow === 5 || $todayDow === 6;

$maiWaSystem = "You are Mai, the agency's AI Account Executive, sending a WhatsApp update to a teammate. Your character: "
    . "analytical and decisive, warm but not chatty, no corporate filler.\n\n"
    . "HARD RULES — these are not suggestions, a long message defeats the entire point:\n"
    . ($isWeekend
        ? "- Today is a WEEKEND day (Friday/Saturday) — do NOT say \"good morning\". Open with a brief weekend-appropriate line addressed to them by "
          . "first name instead, e.g. \"Happy Friday {NAME},\" or \"Hope you're having a good weekend, {NAME} —\", naturally varied, every single time.\n"
        : "- ALWAYS start the message with a morning greeting addressed to them by first name, e.g. \"Good morning {NAME},\" on its own — "
          . "vary the exact phrasing naturally (Good morning / Morning / Morning!) so it doesn't read as a fixed template, but it must always "
          . "include \"good morning\" (or a clear variant of it) plus their first name, every single time.\n")
    . "- STRICT LENGTH LIMIT: the ENTIRE message (including the greeting) must be under 900 characters total, no exceptions — the two-line-per-client "
    . "format below already takes more room than a one-liner, so keep every individual line itself short and punchy rather than dropping the format.\n"
    . "- ONE message only. This is a WhatsApp ping, not an email or a report — nobody will read a wall of text, so being readable matters "
    . "more than being complete.\n"
    . "- Use ⚠️ ONLY for a client with a REAL problem below (cadence behind schedule, or pipeline low/empty). Use ✅ for a client that's fine.\n"
    . "- FORMAT: exactly TWO lines per client, every client, no exceptions and no grouping several clients onto one shared line:\n"
    . "  Line 1: \"{ClientName} {emoji}\" — just the name and status emoji, nothing else.\n"
    . "  Line 2: \"Published: X/Y this week · Scheduled: N in pipeline\" using that client's real numbers below — append a short clause after it "
    . "ONLY if there's an actual cadence or pipeline problem to flag (e.g. \" — last post 6d ago\" or \" — runway low\"), otherwise leave Line 2 at just the two numbers.\n"
    . "  A blank line between each client's two-line block. Never merge a client's two lines into one, never blend two clients together.\n"
    . "- NEVER repeat/paste full report text or add extra sentences per client beyond the two lines above.\n"
    . "- End with ONE short line pointing to SocialFlow notifications for full details and inviting them to ask you for more — not a full sentence per client repeating this.\n"
    . "- Never use markdown headers, '#', or bullet-point '-' lists — write like a real WhatsApp text (short lines/emoji are fine, formal lists/headers are not).";

foreach ($recipientFindings as $email => $entry) {
    if (empty($entry['clients'])) continue;
    $lines = [];
    foreach ($entry['clients'] as $name => $facts) {
        $parts = [];
        // Always lead with the raw numbers — present for every account,
        // flagged or not, so the message never reduces a fine account to a
        // content-free "on track" and gives the AM something concrete to
        // spot-check against reality.
        if (!empty($facts['stats'])) $parts[] = $facts['stats'];
        if (!empty($facts['scheduled_count'])) $parts[] = $facts['scheduled_count'];
        if (!empty($facts['cadence'])) $parts[] = "cadence: " . $facts['cadence'];
        if (!empty($facts['pipeline'])) $parts[] = "pipeline: " . $facts['pipeline'];
        if (!empty($facts['report'])) $parts[] = "today's read: " . $facts['report'];
        if (!$parts) $parts[] = "no issues, nothing new to flag";
        $lines[] = "{$name} — " . implode(" | ", $parts);
    }
    $nameParts = explode(' ', trim($entry['name'] ?? ''));
    $firstName = trim($nameParts[0] ?? '');
    $nameHint = $firstName !== '' ? $firstName : '(unknown — just say Good morning, with no name)';
    $userMsg = "Recipient's first name: {$nameHint}\n\nToday's findings across your accounts:\n" . implode("\n", $lines) . "\n\nWrite the one WhatsApp message now, starting with the greeting, two lines per client as instructed.";
    [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 700, 'system' => $maiWaSystem, 'messages' => [['role' => 'user', 'content' => $userMsg]]]);
    $msg = '';
    if ($status >= 200 && $status < 300) {
        foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $msg .= $block['text']; }
    }
    $msg = trim($msg);
    $greeting = $isWeekend
        ? "Happy weekend" . ($firstName !== '' ? " {$firstName}" : '') . ","
        : "Good morning" . ($firstName !== '' ? " {$firstName}" : '') . ",";
    // Belt-and-suspenders: never trust the model's compliance with either
    // the greeting or the length limit completely. The system prompt
    // explicitly allows "Good morning" / "Morning" / "Morning!" as natural
    // variants on a weekday — this check only ever looked for "good
    // morning", so a message that opened with the equally-valid bare
    // "Morning {name}," wasn't recognized as already having a greeting,
    // and got a SECOND "Good morning {name}," prepended on top of it. On a
    // weekend day, "morning" is never expected at all — check for weekend-
    // style phrasing instead so a message that already opened with
    // "Happy Friday" etc. doesn't get a redundant greeting stapled on too.
    $hasGreeting = $isWeekend
        ? preg_match('/\b(happy|weekend|friday|saturday)\b|صباح\s*الخير|عطل/iu', mb_substr($msg, 0, 60))
        : preg_match('/\bmorning\b|صباح\s*الخير/iu', mb_substr($msg, 0, 60));
    if ($msg !== '' && !$hasGreeting) {
        $msg = $greeting . "\n" . $msg;
    }
    // The system prompt asks for under 900 characters (greeting included) —
    // raised from 550 now that the format is two lines per client instead
    // of one, but a message nobody will actually read still defeats the
    // point, so still hard-capped. Cut at the last whole word before the
    // limit rather than mid-word.
    if (mb_strlen($msg) > 950) {
        $cut = mb_substr($msg, 0, 900);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) $cut = mb_substr($cut, 0, $lastSpace);
        $msg = $cut . "… full details in SocialFlow notifications.";
    }
    if ($msg === '') {
        // Fallback if the AI call itself fails — still one message, still
        // short, just without her usual phrasing variety.
        $msg = "{$greeting} quick account check: " . implode("; ", array_map(
            fn($n, $f) => $n . (!empty($f['cadence']) || !empty($f['pipeline']) ? " ⚠️" : " ✅"),
            array_keys($entry['clients']), array_values($entry['clients'])
        )) . ". Full details in SocialFlow notifications — ask me for more on any account.";
    }
    $prefStmt = $pdo->prepare("SELECT all_disabled, wa_daily_finance_report FROM notification_prefs WHERE user_email = :email LIMIT 1");
    $prefStmt->execute([':email' => $email]);
    $pref = $prefStmt->fetch(PDO::FETCH_ASSOC);
    // No saved prefs row yet means default-on, matching DEFAULT_NOTIF_PREFS on the frontend.
    if ($pref && (!empty($pref['all_disabled']) || $pref['wa_daily_finance_report'] === '0')) continue;
    sendWhatsAppReply($entry['whatsapp_number'], $msg);
}

header('Content-Type: application/json');
echo json_encode($summary);
