<?php
// ================================================================
// SocialFlow — Mai's twice-daily account-manager check-in reports.
//
// Morning report (~12pm, weekdays only): did the AM check social platforms
// and ad accounts for each of their clients, how are results looking,
// have they answered all client communication, what's on today (meetings,
// submissions, lead follow-ups), and any open items from a past contact
// report Mai remembers ("you said you'd do X today — did you?").
//
// End-of-day report (~7pm): follows up on the morning items, confirms
// today's meetings got a contact report logged, and closes with a
// motivational line + thank you.
//
// Each report is a real multi-turn WhatsApp conversation, not a single
// blast — a row in mai_report_sessions tracks progress through a fixed
// checklist (the only thing that's actually SCORED) plus the full
// transcript (logged for context/client-memory, but the free-form
// relationship chat itself is never scored — see MORNING_CHECKLIST /
// EOD_CHECKLIST below).
// ================================================================

require_once __DIR__ . '/pro-lib.php'; // callClaude(), sendWhatsAppReply(), generateProUuid()

const MAI_MORNING_CHECKLIST = [
    'checked_platforms'   => 'Checked social platforms for all assigned clients',
    'checked_ad_accounts' => 'Checked ad accounts for all assigned clients',
    'answered_comms'      => 'Answered all client calls/messages',
    'confirmed_meetings'  => "Confirmed today's meetings",
    'confirmed_submissions' => "Confirmed today's submissions/deliverables",
    'confirmed_lead_followups' => 'Confirmed lead calls/follow-ups for today',
];

const MAI_EOD_CHECKLIST = [
    'meetings_logged'   => "Today's meetings each have a contact report logged",
    'submissions_done'  => "Today's submissions/deliverables were actually delivered",
    'morning_items_done' => 'Any items still open from the morning check-in are resolved or explained',
];

function maiChecklistFor($reportType) {
    return $reportType === 'eod' ? MAI_EOD_CHECKLIST : MAI_MORNING_CHECKLIST;
}

// Builds the per-client context block Mai uses to sound like she actually
// knows the accounts — recent client_memory facts + any still-open action
// items from a contact report ("you agreed you'd do X today").
function maiBuildClientContext(PDO $pdo, $accountManagerId) {
    $clients = $pdo->prepare("SELECT id, name FROM clients WHERE account_manager_id = :id AND status = 'active'");
    $clients->execute([':id' => $accountManagerId]);
    $rows = $clients->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['names' => [], 'context' => 'This account manager has no active clients assigned.'];

    $blocks = [];
    $names = [];
    foreach ($rows as $c) {
        $names[] = $c['name'];
        $mem = $pdo->prepare("SELECT `key`, value FROM client_memory WHERE client_id = :cid ORDER BY priority DESC, updated_at DESC LIMIT 5");
        $mem->execute([':cid' => $c['id']]);
        $memRows = $mem->fetchAll(PDO::FETCH_ASSOC);
        $memLines = $memRows ? implode("\n", array_map(fn($m) => "  - {$m['key']}: {$m['value']}", $memRows)) : '  (no memory on file yet)';

        $cr = $pdo->prepare("SELECT action_items, created_at FROM contact_reports WHERE client_id = :cid AND action_items IS NOT NULL AND action_items != '' ORDER BY created_at DESC LIMIT 1");
        $cr->execute([':cid' => $c['id']]);
        $crRow = $cr->fetch(PDO::FETCH_ASSOC);
        $actionLine = $crRow ? "  Open action item from {$crRow['created_at']}: {$crRow['action_items']}" : '';

        $blocks[] = "Client \"{$c['name']}\":\n{$memLines}" . ($actionLine ? "\n{$actionLine}" : '');
    }
    return ['names' => $names, 'context' => implode("\n\n", $blocks)];
}

function maiPersonaSystem() {
    return "You are Mai, the agency's AI Account Executive — warm, sharp, genuinely on top of every client's numbers, and a little proud of it. "
        . "You're checking in with an account manager over WhatsApp. Your voice: friendly and encouraging, never robotic or like a compliance checklist read "
        . "aloud — lead with real client info you know (\"I saw bino's reach was up yesterday, is that from...\") to make her curious and engaged, not just "
        . "interrogated. Ask things you already know the answer to when it lets you confirm/update your own memory with what she says. If a client's numbers "
        . "are flat or falling, ask why and whether she has a plan to fix it. Keep every message SHORT — a few sentences max, real WhatsApp texting, not an essay. "
        . "One question (or tight cluster of related questions) at a time, never a big numbered list.";
}

// Kicks off a report session for one account manager: builds context,
// asks Claude for the opening message, inserts the session row, sends it.
function maiStartReportSession(PDO $pdo, array $am, $reportType) {
    $today = date('Y-m-d');
    // Idempotent — the UNIQUE KEY on (account_manager_id, report_type,
    // report_date) means a second cron run today silently no-ops here.
    $exists = $pdo->prepare("SELECT id FROM mai_report_sessions WHERE account_manager_id = :id AND report_type = :t AND report_date = :d");
    $exists->execute([':id' => $am['id'], ':t' => $reportType, ':d' => $today]);
    if ($exists->fetchColumn()) return null;

    if (!waPrefAllows($pdo, $am['email'], 'wa_am_reports')) return null;

    $checklist = maiChecklistFor($reportType);
    $checklistState = [];
    foreach ($checklist as $key => $label) { $checklistState[$key] = ['label' => $label, 'done' => false, 'note' => null]; }

    $ctx = maiBuildClientContext($pdo, $am['id']);
    $firstName = explode(' ', trim($am['name']))[0];

    if ($reportType === 'morning') {
        $userMsg = "It's midday — time for {$firstName}'s morning check-in. Her clients and what you know about them:\n\n{$ctx['context']}\n\n"
            . "Checklist to work through over the conversation (don't list it to her, just naturally get answers to all of it):\n"
            . implode("\n", array_map(fn($l) => "- {$l}", $checklist)) . "\n\n"
            . "Start the conversation now — greet her, lead with one real, specific, interesting thing you noticed about one of her clients "
            . "(good or concerning) to open naturally, then ease into your first real question. Write ONLY the WhatsApp message, nothing else.";
    } else {
        $morningStmt = $pdo->prepare("SELECT checklist FROM mai_report_sessions WHERE account_manager_id = :id AND report_type = 'morning' AND report_date = :d LIMIT 1");
        $morningStmt->execute([':id' => $am['id'], ':d' => $today]);
        $morningChecklist = $morningStmt->fetchColumn();
        $morningSummary = 'She has no morning check-in on record for today — ask about that too.';
        if ($morningChecklist) {
            $parsed = json_decode($morningChecklist, true) ?: [];
            $openItems = array_filter($parsed, fn($v) => empty($v['done']));
            $morningSummary = $openItems
                ? "From this morning, still open: " . implode('; ', array_map(fn($v) => $v['label'], $openItems))
                : 'Everything from this morning was confirmed done.';
        }
        $userMsg = "It's end of day — time for {$firstName}'s wrap-up check-in. Her clients:\n\n{$ctx['context']}\n\n{$morningSummary}\n\n"
            . "Checklist to work through (don't list it to her):\n" . implode("\n", array_map(fn($l) => "- {$l}", $checklist)) . "\n\n"
            . "Start the conversation now — friendly end-of-day tone, follow up on anything still open from this morning first if relevant. "
            . "Write ONLY the WhatsApp message, nothing else.";
    }

    [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 300, 'system' => maiPersonaSystem(), 'messages' => [['role' => 'user', 'content' => $userMsg]]]);
    $opener = '';
    if ($status >= 200 && $status < 300) {
        foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $opener .= $block['text']; }
    }
    $opener = trim($opener);
    if ($opener === '') {
        $opener = $reportType === 'morning'
            ? "Hi {$firstName}! Quick check-in — have you had a chance to look at your clients' platforms and ad accounts today?"
            : "Hi {$firstName}, end-of-day check-in — how did today go with your clients?";
    }

    $sessionId = generateProUuid();
    $ins = $pdo->prepare("INSERT INTO mai_report_sessions (id, account_manager_id, account_manager_name, account_manager_email, report_type, report_date, status, checklist, transcript) VALUES (:id, :amid, :amname, :amemail, :type, :date, 'in_progress', :checklist, :transcript)");
    $ins->execute([
        ':id' => $sessionId, ':amid' => $am['id'], ':amname' => $am['name'], ':amemail' => $am['email'],
        ':type' => $reportType, ':date' => $today,
        ':checklist' => json_encode($checklistState),
        ':transcript' => json_encode([['role' => 'assistant', 'content' => $opener, 'at' => date('c')]]),
    ]);

    if (!empty($am['whatsapp_number'])) sendWhatsAppReply($am['whatsapp_number'], $opener);
    return $sessionId;
}

// Finds the account manager's active (in_progress, today's) report session,
// if any — called from wa-webhook.php to decide whether an incoming
// message should route here instead of to the general Pro assistant.
function maiFindActiveReportSession(PDO $pdo, $accountManagerId) {
    if (!$accountManagerId) return null;
    $stmt = $pdo->prepare("SELECT * FROM mai_report_sessions WHERE account_manager_id = :id AND status = 'in_progress' AND report_date = CURDATE() ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([':id' => $accountManagerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Continues an in-progress session with the AM's latest WhatsApp reply —
// one Claude call decides the next message, which checklist items are now
// satisfied, any client_memory facts worth saving, and whether the
// conversation is done. Sends the reply itself; returns nothing (the
// caller — wa-webhook.php — should NOT also send a reply).
function maiContinueReportSession(PDO $pdo, array $session, $incomingText) {
    $transcript = json_decode($session['transcript'], true) ?: [];
    $transcript[] = ['role' => 'user', 'content' => $incomingText, 'at' => date('c')];

    $checklist = json_decode($session['checklist'], true) ?: [];
    $openItems = array_filter($checklist, fn($v) => empty($v['done']));
    $checklistBlock = $openItems
        ? implode("\n", array_map(fn($k, $v) => "- [{$k}] {$v['label']}", array_keys($openItems), array_values($openItems)))
        : '(all checklist items already confirmed — wrap up naturally)';

    $historyBlock = implode("\n", array_map(fn($m) => ($m['role'] === 'assistant' ? 'Mai' : 'AM') . ': ' . $m['content'], $transcript));

    $sys = maiPersonaSystem() . "\n\nThis is turn N of an ongoing check-in conversation. Still-open checklist items to get through naturally "
        . "(never read them out as a list — weave them into normal conversation):\n{$checklistBlock}\n\n"
        . "Conversation so far:\n{$historyBlock}\n\n"
        . "Decide: does her last message resolve any open checklist item(s)? Does it contain a fact worth remembering about a client "
        . "(a real update, a plan, a number, a promise)? Should the conversation continue (more open items, or something worth digging into — "
        . "e.g. she mentioned a client's numbers are down, ask why and whether she has a plan) or wrap up now (all items covered)?\n\n"
        . "If wrapping up, your reply MUST end with a short motivational line about the business/team and a thank-you — genuine, not generic corporate filler.\n\n"
        . "Return ONLY this JSON (no markdown, no other text):\n"
        . '{"reply":"the next WhatsApp message to send her","checklist_done":["item_key",...],"client_memory":[{"client_name":"...","key":"...","value":"..."}],"complete":true|false}';

    [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 500, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => 'Continue the conversation now, following the instructions exactly.']]]);
    $raw = '';
    if ($status >= 200 && $status < 300) {
        foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $raw .= $block['text']; }
    }
    $parsed = null;
    if (preg_match('/\{[\s\S]*\}/', $raw, $m)) $parsed = json_decode($m[0], true);

    $reply = trim($parsed['reply'] ?? '') ?: "Got it, thanks! Let me know if there's anything else.";
    $complete = !empty($parsed['complete']);

    foreach (($parsed['checklist_done'] ?? []) as $key) {
        if (isset($checklist[$key])) $checklist[$key]['done'] = true;
    }
    foreach (($parsed['client_memory'] ?? []) as $fact) {
        $cname = trim($fact['client_name'] ?? '');
        $key = trim($fact['key'] ?? '');
        $value = trim($fact['value'] ?? '');
        if ($cname === '' || $key === '' || $value === '') continue;
        $cstmt = $pdo->prepare("SELECT id FROM clients WHERE name = :n LIMIT 1");
        $cstmt->execute([':n' => $cname]);
        $clientId = $cstmt->fetchColumn();
        if (!$clientId) continue;
        $pdo->prepare("INSERT INTO client_memory (id, client_id, client_name, `key`, value, type) VALUES (:id, :cid, :cname, :k, :v, 'am_daily_report')")
            ->execute([':id' => generateProUuid(), ':cid' => $clientId, ':cname' => $cname, ':k' => $key, ':v' => $value]);
    }

    $transcript[] = ['role' => 'assistant', 'content' => $reply, 'at' => date('c')];

    $stillOpen = array_filter($checklist, fn($v) => empty($v['done']));
    // Belt-and-suspenders — if Claude says complete but items are still
    // open, trust it (she may have explained a valid reason) but don't
    // silently score those as done; the score reflects reality either way.
    $status_ = $complete ? 'completed' : 'in_progress';
    $score = null; $maxScore = null;
    if ($complete) {
        $maxScore = count($checklist) * 10;
        $score = 0;
        foreach ($checklist as $v) { if (!empty($v['done'])) $score += 10; }
    }

    $upd = $pdo->prepare("UPDATE mai_report_sessions SET checklist = :checklist, transcript = :transcript, status = :status, score = :score, max_score = :max_score, completed_at = " . ($complete ? 'NOW()' : 'NULL') . " WHERE id = :id");
    $upd->execute([
        ':checklist' => json_encode($checklist), ':transcript' => json_encode($transcript),
        ':status' => $status_, ':score' => $score, ':max_score' => $maxScore, ':id' => $session['id'],
    ]);

    if (!empty($session['account_manager_id'])) {
        $phoneStmt = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id");
        $phoneStmt->execute([':id' => $session['account_manager_id']]);
        $phone = $phoneStmt->fetchColumn();
        if ($phone) sendWhatsAppReply($phone, $reply);
    }
}

// Run separately, ~1hr after the report-starting cron (giving AMs time to
// actually reply) — one short WhatsApp summary to every admin per report
// round, highlighting what actually needs their attention: low scores,
// account managers who never responded, and anything concerning Mai
// surfaced (declining accounts, unresolved items). Never repeats a
// digest for the same day/type twice, same idempotency pattern as
// maiStartReportSession.
function maiSendAdminDigest(PDO $pdo, $reportType) {
    $today = date('Y-m-d');
    $flagStmt = $pdo->prepare("SELECT 1 FROM mai_report_digests WHERE report_type = :t AND report_date = :d");
    $flagStmt->execute([':t' => $reportType, ':d' => $today]);
    if ($flagStmt->fetchColumn()) return;
    $pdo->prepare("INSERT IGNORE INTO mai_report_digests (report_type, report_date) VALUES (:t, :d)")->execute([':t' => $reportType, ':d' => $today]);

    $ams = $pdo->query("SELECT id, name FROM team_members WHERE role = 'account_manager' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$ams) return;

    $sessStmt = $pdo->prepare("SELECT * FROM mai_report_sessions WHERE account_manager_id = :id AND report_type = :t AND report_date = :d LIMIT 1");
    $lines = [];
    foreach ($ams as $am) {
        $sessStmt->execute([':id' => $am['id'], ':t' => $reportType, ':d' => $today]);
        $s = $sessStmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) { $lines[] = "{$am['name']}: no check-in started (not on WhatsApp or opted out)"; continue; }
        if ($s['status'] !== 'completed') { $lines[] = "{$am['name']}: check-in started but never finished replying"; continue; }
        $checklist = json_decode($s['checklist'], true) ?: [];
        $openLabels = array_map(fn($v) => $v['label'], array_filter($checklist, fn($v) => empty($v['done'])));
        $transcript = json_decode($s['transcript'], true) ?: [];
        $amText = implode(' | ', array_map(fn($m) => $m['content'], array_filter($transcript, fn($m) => $m['role'] === 'user')));
        $lines[] = "{$am['name']}: score {$s['score']}/{$s['max_score']}." . ($openLabels ? ' Open: ' . implode(', ', $openLabels) . '.' : '') . " What she said: \"" . mb_substr($amText, 0, 400) . "\"";
    }

    $sys = "You are Mai, the agency's AI Account Executive, writing ONE short WhatsApp summary for the admin/owner after all account managers' "
        . ($reportType === 'morning' ? 'morning' : 'end-of-day') . " check-ins. Highlight ONLY what actually needs attention — low scores, "
        . "unfinished/missed check-ins, declining client numbers, unresolved action items, anything concerning. Account managers who are fully fine "
        . "get at most one grouped line (e.g. \"X and Y are all good\"), never individual praise paragraphs. STRICT LIMIT: under 500 characters total. "
        . "No markdown headers or bullet lists — short lines, WhatsApp style.";
    $userMsg = "Today's " . ($reportType === 'morning' ? 'morning' : 'end-of-day') . " check-in results:\n" . implode("\n", $lines) . "\n\nWrite the summary now.";
    [$status, $data] = callClaude(['model' => 'claude-sonnet-4-6', 'max_tokens' => 400, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $userMsg]]]);
    $summary = '';
    if ($status >= 200 && $status < 300) {
        foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $summary .= $block['text']; }
    }
    $summary = trim($summary) ?: ("AM check-in summary (" . ($reportType === 'morning' ? 'morning' : 'EOD') . "):\n" . implode("\n", $lines));

    $admins = $pdo->query(
        "SELECT tm.whatsapp_number, np.all_disabled, np.wa_am_reports_digest FROM team_members tm
         LEFT JOIN notification_prefs np ON np.user_email = tm.email
         WHERE tm.role = 'admin' AND tm.status = 'active' AND tm.whatsapp_number IS NOT NULL AND tm.whatsapp_number != ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admins as $admin) {
        if (!empty($admin['all_disabled']) || $admin['wa_am_reports_digest'] === '0') continue;
        sendWhatsAppReply($admin['whatsapp_number'], $summary);
    }
}
