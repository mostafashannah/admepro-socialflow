<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * pro-leads-lib.php — Pro's update_lead tool: an account manager replies on WhatsApp about a lead that was assigned to them
 * ("called Mona, no answer", "she'll look at the website, remind me tomorrow 8pm", "not interested") and the lead is really updated:
 * status, follow-up date, an Activity line, an optional WhatsApp reminder for the sender, and the standard message to the lead.
 */
require_once __DIR__ . '/lead-send-lib.php';

function leadTools(): array {
    return [[
        'name' => 'update_lead',
        'description' => 'Update or REASSIGN a LEAD. To hand a lead to someone else ("assign to Menna", "give this lead to Mona") use action=reassign with assign_to; if the sender does not name the lead (they are replying to a "contact captured" alert), pass lead="latest" and the most recent open lead is used. Update a LEAD that was assigned to the sender (the "New lead assigned to you: NAME — PHONE. Source: X." notifications). Use it whenever the sender reports what happened with a lead: no answer, asked to be called later, will look at something and get back, not interested / not qualified, or just a note. This really changes the lead in the system (status, follow-up date, activity log) and can set a WhatsApp reminder for the sender and send the lead the standard "we tried to call you" message. NEVER treat this as a client contact report and never ask which client: a lead is identified by its name or phone as in the notification.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'lead' => ['type' => 'string', 'description' => 'The lead\'s name or phone number exactly as the sender said / as in the notification. Their assigned open leads are listed in your context.'],
                'action' => ['type' => 'string', 'enum' => ['no_answer', 'call_later', 'follow_up', 'not_qualified', 'note', 'reassign'], 'description' => 'reassign = hand the lead to another team member (also set assign_to); no_answer = called and nobody picked up; call_later = the lead asked to be called at another time; follow_up = the lead needs time (e.g. to look at the website) and the sender will follow up; not_qualified = not interested / not a fit (closes the lead); note = only record what was said.'],
                'followup_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, the day to follow up (resolved from today\'s date). Omit to use tomorrow for no_answer / call_later / follow_up.'],
                'remind_at' => ['type' => 'string', 'description' => 'Only if the sender asked to be reminded: exact date+time "YYYY-MM-DD HH:MM:SS" 24h, resolved from today\'s date and the current time (in the sender\'s own time zone, Cairo).'],
                'note' => ['type' => 'string', 'description' => 'What happened, in the sender\'s own words, short. It is saved in the lead\'s Activity.'],
                'assign_to' => ['type' => 'string', 'description' => 'Only for action=reassign: the team member to hand the lead to, by first name, full name or email ("menna", "Mona Khalid").'],
                'send_message' => ['type' => 'boolean', 'description' => 'Send the lead the standard message for no_answer / call_later / not_qualified. Default true for no_answer and call_later, false otherwise; pass false if the sender says not to message them.'],
            ],
            'required' => ['lead', 'action'],
        ],
    ]];
}

function runLeadTool(PDO $pdo, array $input, string $senderRole, ?string $senderId, ?string $senderName): array {
    $q = trim((string)($input['lead'] ?? '')); $action = (string)($input['action'] ?? '');
    if (!in_array($action, ['no_answer', 'call_later', 'follow_up', 'not_qualified', 'note', 'reassign'], true) || ($q === '' && $action !== 'reassign')) return ['error' => 'Say which lead and what happened.'];
    $me = $pdo->prepare("SELECT email FROM team_members WHERE id = :i"); $me->execute([':i' => $senderId]); $email = (string)$me->fetchColumn();
    $isAdmin = $senderRole === 'team:admin';
    if ($action === 'reassign' && ($q === '' || in_array(mb_strtolower($q), ['latest', 'last', 'this', 'it', 'the lead'], true))) {
        // the sender replied to a "contact captured" alert without naming the lead: use the newest open lead (last 12 h), only if it is unambiguous
        $rs = $pdo->prepare("SELECT id, name, phone, status, assigned_to, client_id, notes, followup_date FROM leads WHERE status NOT IN ('closed_won','closed_lost','dead') AND created_at >= (NOW() - INTERVAL 12 HOUR)" . ($isAdmin ? "" : " AND assigned_to = :me") . " ORDER BY created_at DESC LIMIT 6");
        $rs->execute($isAdmin ? [] : [':me' => $email]); $recent = $rs->fetchAll(PDO::FETCH_ASSOC);
        if (!$recent) return ['error' => 'No new lead in the last 12 hours — ask for the lead\'s name or phone.'];
        if (count($recent) > 1) return ['error' => 'More than one recent lead — ask which one.', 'matches' => array_map(fn($h) => ['name' => $h['name'], 'phone' => $h['phone'], 'assigned_to' => $h['assigned_to']], $recent)];
        return lead_reassign($pdo, $recent[0], (string)($input['assign_to'] ?? ''), $senderName, $email, $senderId);
    }
    $digits = preg_replace('/\D/', '', $q); $nameLike = '%' . $q . '%';
    $sql = "SELECT id, name, phone, status, assigned_to, client_id, notes, followup_date FROM leads WHERE status NOT IN ('closed_won','closed_lost','dead') AND (name LIKE :n" . (strlen($digits) >= 6 ? " OR REPLACE(REPLACE(phone,' ',''),'+','') LIKE :p" : "") . ")" . ($isAdmin ? "" : " AND assigned_to = :me") . " ORDER BY created_at DESC LIMIT 6";
    $st = $pdo->prepare($sql); $bind = [':n' => $nameLike]; if (strlen($digits) >= 6) $bind[':p'] = '%' . substr($digits, -9) . '%'; if (!$isAdmin) $bind[':me'] = $email;
    $st->execute($bind); $hits = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($hits) > 1) { $exact = array_values(array_filter($hits, fn($h) => mb_strtolower(trim($h['name'])) === mb_strtolower($q))); if (count($exact) === 1) $hits = $exact; }
    if (!$hits) return ['error' => 'No open lead' . ($isAdmin ? '' : ' assigned to you') . ' matches "' . $q . '". Check the name or phone in the notification.'];
    if (count($hits) > 1) return ['error' => 'More than one lead matches — ask which one.', 'matches' => array_map(fn($h) => ['name' => $h['name'], 'phone' => $h['phone'], 'status' => $h['status']], $hits)];
    $lead = $hits[0];
    if ($action === 'reassign') return lead_reassign($pdo, $lead, (string)($input['assign_to'] ?? ''), $senderName, $email, $senderId);

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $fd = trim((string)($input['followup_date'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) $fd = '';
    $newStatus = $lead['status']; $follow = null;
    if ($action === 'not_qualified') $newStatus = 'closed_lost';
    elseif ($action === 'call_later' || $action === 'follow_up') { $newStatus = 'follow_up'; $follow = $fd ?: $tomorrow; }
    elseif ($action === 'no_answer') { if (!$lead['status'] || $lead['status'] === 'new') $newStatus = 'contacted'; $follow = $fd ?: $tomorrow; }
    elseif ($action === 'note' && $fd !== '') $follow = $fd;
    $pdo->prepare("UPDATE leads SET status = :s" . ($follow !== null ? ", followup_date = :f" : "") . " WHERE id = :id")->execute([':s' => $newStatus, ':id' => $lead['id']] + ($follow !== null ? [':f' => $follow] : []));

    // the standard message to the lead (same way they contacted us)
    $sendDefault = in_array($action, ['no_answer', 'call_later'], true);
    $doSend = array_key_exists('send_message', $input) ? !empty($input['send_message']) : $sendDefault;
    $msgResult = null;
    if ($doSend && in_array($action, ['no_answer', 'call_later', 'not_qualified'], true)) {
        $ctx = lead_context($pdo, $lead);
        $msgResult = lead_send($pdo, $lead, lead_action_text($action, $ctx['ar'], $ctx['first']), $ctx);
    }
    // reminder for the sender
    $remNote = null;
    if (!empty($input['remind_at'])) {
        $label = ['no_answer' => 'call back', 'call_later' => 'call back', 'follow_up' => 'follow up with', 'not_qualified' => 'check', 'note' => 'follow up with'][$action];
        $rr = runHrTool($pdo, 'set_reminder', ['remind_at' => (string)$input['remind_at'], 'message' => "Reminder: {$label} {$lead['name']}" . (!empty($lead['phone']) ? " ({$lead['phone']})" : '') . (!empty($input['note']) ? ' — ' . trim((string)$input['note']) : '')], $senderId, $senderName);
        $remNote = !empty($rr['ok']) ? $rr['message'] : ('Reminder NOT set: ' . ($rr['error'] ?? 'unknown error'));
    }
    $L = ['no_answer' => '📵 No answer', 'call_later' => '⏰ Call later', 'follow_up' => '📞 Follow-up', 'not_qualified' => '✖ Not qualified', 'note' => '📝 Note'][$action];
    $content = $L . ($action === 'not_qualified' ? ' — lead closed' : ($follow !== null ? ' — follow-up ' . $follow : '')) . '. ' . (!empty($input['note']) ? trim((string)$input['note']) . '. ' : '')
        . ($msgResult ? ($msgResult['sent'] ? "{$msgResult['channel_label']} message sent to the lead." : 'Message to the lead NOT sent: ' . ($msgResult['send_error'] ?? 'unknown reason')) : '') . ($remNote ? ' ' . $remNote : '') . ' (via Pro on WhatsApp)';
    try { $pdo->prepare("INSERT INTO lead_activities (id, lead_id, type, content, followup_date, author_name, author_email) VALUES (UUID(), :l, :t, :c, :f, :a, :e)")
        ->execute([':l' => $lead['id'], ':t' => in_array($action, ['no_answer', 'call_later', 'follow_up'], true) ? 'call' : 'note', ':c' => trim($content), ':f' => $follow, ':a' => $senderName, ':e' => $email]); } catch (Throwable $e) {}
    return ['ok' => true, 'lead' => $lead['name'], 'status' => $newStatus, 'followup_date' => $follow, 'reminder' => $remNote,
            'message_to_lead' => $msgResult ? ($msgResult['sent'] ? 'sent on ' . $msgResult['channel_label'] : 'NOT sent: ' . ($msgResult['send_error'] ?? 'unknown reason')) : 'not sent'];
}

/** Hand a lead to another active team member: update the assignee, log it in the lead's Activity and tell the new owner on WhatsApp. */
function lead_reassign(PDO $pdo, array $lead, string $to, ?string $senderName, string $senderEmail, ?string $senderId): array {
    $to = trim($to);
    if ($to === '') return ['error' => 'Say who to assign it to.'];
    $st = $pdo->prepare("SELECT id, name, email, whatsapp_number FROM team_members WHERE status = 'active' AND (name LIKE :n1 OR email LIKE :n2) ORDER BY name LIMIT 8");
    $st->execute([':n1' => '%' . $to . '%', ':n2' => '%' . $to . '%']); $c = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($c) > 1) { $ex = array_values(array_filter($c, fn($r) => mb_strtolower(trim($r['name'])) === mb_strtolower($to) || mb_strtolower($r['email']) === mb_strtolower($to))); if (count($ex) === 1) $c = $ex; }
    if (!$c) return ['error' => 'No active team member matches "' . $to . '".'];
    if (count($c) > 1) return ['error' => 'More than one team member matches — ask which one.', 'matches' => array_map(fn($r) => $r['name'], $c)];
    $new = $c[0];
    if (mb_strtolower((string)$lead['assigned_to']) === mb_strtolower($new['email'])) return ['ok' => true, 'lead' => $lead['name'], 'assigned_to' => $new['name'], 'note' => 'Already assigned to them — nothing changed.'];
    $pdo->prepare("UPDATE leads SET assigned_to = :e, assigned_at = NOW() WHERE id = :id")->execute([':e' => $new['email'], ':id' => $lead['id']]);
    try { $pdo->prepare("INSERT INTO lead_activities (id, lead_id, type, content, author_name, author_email) VALUES (UUID(), :l, 'note', :c, :a, :e)")
        ->execute([':l' => $lead['id'], ':c' => 'Reassigned from ' . ($lead['assigned_to'] ?: 'nobody') . ' to ' . $new['name'] . ' (via Pro on WhatsApp)', ':a' => $senderName, ':e' => $senderEmail]); } catch (Throwable $e) {}
    $notified = false;
    if (mb_strtolower($new['email']) !== mb_strtolower($senderEmail) && !empty($new['whatsapp_number']) && function_exists('sendWhatsAppReply')) {
        try { sendWhatsAppReply($new['whatsapp_number'], "New lead assigned to you: \"{$lead['name']}\"" . (!empty($lead['phone']) ? " — {$lead['phone']}" : '') . ". " . ($senderName ?: 'A teammate') . " handed it to you — take a look when you can."); $notified = true; } catch (Throwable $e) {}
    }
    return ['ok' => true, 'lead' => $lead['name'], 'phone' => $lead['phone'], 'assigned_to' => $new['name'], 'previous' => $lead['assigned_to'] ?: null, 'new_owner_notified_on_whatsapp' => $notified];
}
