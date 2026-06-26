<?php
// ================================================================
// SocialFlow — per-client AI reply-bot.
// Builds a system prompt from a client's Client Brain data (tone,
// brand voice) plus a dedicated "reply bot brain" (custom instructions
// configured per client in reply_bot_settings.brain) and recent
// human-sent replies (as few-shot style examples), then asks Claude
// to draft a reply to the latest inbound customer message.
//
// Used by meta-inbox-webhook.php right after an inbound DM is stored.
// ================================================================
require_once __DIR__ . '/pro-lib.php';

function getReplyBotSettings(PDO $pdo, string $clientId) {
    $stmt = $pdo->prepare("SELECT * FROM reply_bot_settings WHERE client_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['channels'] = json_decode($row['channels'] ?? '[]', true) ?: [];
    return $row;
}

function findIntegrationCreds(PDO $pdo, string $clientId, string $channel) {
    $appKey = $channel === 'instagram' ? 'instagram' : 'facebook';
    $stmt = $pdo->prepare("SELECT credentials FROM integrations WHERE client_id = :cid AND app_key = :k AND status = 'active' LIMIT 1");
    $stmt->execute([':cid' => $clientId, ':k' => $appKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
    if (empty($creds['page_id']) || empty($creds['access_token'])) return null;
    return $creds;
}

// Builds the Claude system prompt from Client Brain + reply-bot brain + reply history.
function buildReplyBotSystemPrompt(PDO $pdo, string $clientId, string $clientName, string $botBrain) {
    $parts = [];
    $parts[] = "You are the AI auto-reply assistant for {$clientName}'s social media inbox (Instagram/Messenger DMs), built into SocialFlow. "
              . "Write ONE short, natural reply to the customer's latest message below — a few sentences max, no markdown, no headers, "
              . "matching this client's actual tone and the way they typically reply. Reply only with the message text itself, nothing else.";

    $stmt = $pdo->prepare("SELECT tone, summary, keywords, priorities, industry_context, content_preferences FROM client_knowledge WHERE client_id = :cid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':cid' => $clientId]);
    if ($k = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bits = [];
        if (!empty($k['tone'])) $bits[] = "Tone: {$k['tone']}";
        if (!empty($k['summary'])) $bits[] = "About the client: {$k['summary']}";
        if (!empty($k['industry_context'])) $bits[] = "Industry context: {$k['industry_context']}";
        if (!empty($k['priorities'])) $bits[] = "Priorities: {$k['priorities']}";
        if ($bits) $parts[] = "Client Brain notes:\n" . implode("\n", $bits);
    }

    $stmt = $pdo->prepare("SELECT value FROM client_memory WHERE client_id = :cid AND `key` = 'brand_voice_paragraph' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([':cid' => $clientId]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($m['value'])) $parts[] = "Brand voice:\n{$m['value']}";
    }

    if (trim($botBrain) !== '') {
        $parts[] = "Specific instructions set by the agency for this client's auto-replies — follow these closely:\n" . trim($botBrain);
    }

    // Few-shot: last human-sent replies for this client, so the bot mimics actual phrasing.
    $stmt = $pdo->prepare("SELECT message_text FROM customer_messages WHERE client_id = :cid AND direction = 'out' AND sent_by = 'human' ORDER BY created_at DESC LIMIT 12");
    $stmt->execute([':cid' => $clientId]);
    $examples = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'message_text');
    if ($examples) {
        $list = implode("\n", array_map(fn($t) => "- {$t}", array_filter($examples)));
        $parts[] = "Examples of how the team has actually replied to customers before — match this style and phrasing pattern:\n{$list}";
    }

    $parts[] = "If you cannot give a confident, on-brand answer (e.g. it needs a human decision, a price you don't know, or a complaint), "
              . "reply with exactly: NEEDS_HUMAN";

    return implode("\n\n", $parts);
}

// Returns the drafted reply text, or null (on failure, or if Claude opts out via NEEDS_HUMAN).
function generateBotReply(PDO $pdo, string $clientId, string $clientName, string $botBrain, array $threadMessages) {
    $system = buildReplyBotSystemPrompt($pdo, $clientId, $clientName, $botBrain);

    $convo = implode("\n", array_map(
        fn($m) => ($m['direction'] === 'in' ? 'Customer' : 'Agency') . ': ' . $m['message_text'],
        $threadMessages
    ));

    $payload = [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 400,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => "Conversation so far:\n{$convo}\n\nDraft the next reply from the agency."]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) return null;

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if ($text === '' || $text === 'NEEDS_HUMAN') return null;
    return $text;
}

// Sends a DM reply via the Graph API (same shape as meta-send-reply.php).
function sendMetaDM(string $channel, string $pageId, string $accessToken, string $recipientId, string $message) {
    $endpoint = "https://graph.facebook.com/v19.0/{$pageId}/messages";
    $post_data = [
        'recipient'    => json_encode(['id' => $recipientId]),
        'message'      => json_encode(['text' => $message]),
        'access_token' => $accessToken,
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($curl_err) return [502, null];
    return [$http_code, json_decode($response, true)];
}

function storeBotMessage(PDO $pdo, $clientId, $clientName, $channel, $customerId, $customerName, $text, $draftStatus, $threadStatus) {
    $stmt = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status, draft_status) VALUES (:cid, :cname, :ch, :custid, :custname, 'out', :txt, 'bot', :tstatus, :dstatus)");
    $stmt->execute([':cid'=>$clientId, ':cname'=>$clientName, ':ch'=>$channel, ':custid'=>$customerId, ':custname'=>$customerName, ':txt'=>$text, ':tstatus'=>$threadStatus, ':dstatus'=>$draftStatus]);
}

// Entry point called from meta-inbox-webhook.php right after an inbound message is stored.
// Looks up this client's reply-bot settings, and if enabled for this channel, drafts a
// reply and either sends it immediately (mode=auto) or stores it as a pending draft
// for a team member to review (mode=approve).
function maybeAutoReply(PDO $pdo, string $clientId, string $clientName, string $channel, string $customerId, ?string $customerName) {
    $settings = getReplyBotSettings($pdo, $clientId);
    if (!$settings || !$settings['enabled']) return;
    if (!in_array($channel, $settings['channels'], true)) return;

    $stmt = $pdo->prepare("SELECT direction, message_text FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid ORDER BY created_at DESC LIMIT 12");
    $stmt->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
    $thread = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$thread) return;

    $reply = generateBotReply($pdo, $clientId, $clientName, (string)($settings['brain'] ?? ''), $thread);
    if (!$reply) return;

    if ($settings['mode'] === 'auto') {
        $creds = findIntegrationCreds($pdo, $clientId, $channel);
        if (!$creds) return; // no credentials to send with — drop rather than fake a sent message
        [$httpCode] = sendMetaDM($channel, $creds['page_id'], $creds['access_token'], $customerId, $reply);
        if ($httpCode < 200 || $httpCode >= 300) return; // send failed — don't record a phantom "sent" message
        storeBotMessage($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $reply, 'sent', 'bot_handled');
    } else {
        storeBotMessage($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $reply, 'pending_review', 'needs_human');
    }
}
