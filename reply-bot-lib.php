<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
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

// Relays an inbound message to a client's own reply-bot system, so it can
// connect through SocialFlow instead of needing its own separate Meta app/
// webhook subscription. Signed the same way our own Meta webhook signature
// works (HMAC-SHA256, sent as X-SocialFlow-Signature: sha256=...) using this
// client's own external_api_key, so their endpoint can verify the request
// really came from us. Best-effort and fire-and-forget-ish (short timeout,
// failure only logged) — a slow/broken client endpoint must never block or
// delay processing of the webhook batch.
function forwardToExternalBot(PDO $pdo, array $settings, string $clientId, string $clientName, string $channel, string $customerId, ?string $customerName, ?string $externalId, ?string $postCaption) {
    $url = trim((string)($settings['external_webhook_url'] ?? ''));
    $key = trim((string)($settings['external_api_key'] ?? ''));
    if (!$url || !$key) { error_log("forwardToExternalBot [{$clientName}/{$channel}]: no webhook URL or API key configured, skipping forward"); return; }

    $stmt = $pdo->prepare("SELECT direction, message_text, attachment_url, created_at FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid ORDER BY created_at DESC LIMIT 12");
    $stmt->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
    $thread = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $payload = json_encode([
        'client_id'      => $clientId,
        'client_name'    => $clientName,
        'channel'        => $channel,
        'customer_id'    => $customerId,
        'customer_name'  => $customerName,
        'external_id'    => $externalId,   // comment_id for fb_comment/ig_comment, null for DMs
        'post_caption'   => $postCaption,  // the post/reel a comment was left on, if applicable
        'thread'         => $thread,       // most recent messages, oldest first
        'sent_at'        => date('c'),
    ]);
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $key);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-SocialFlow-Signature: {$signature}"],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) {
        error_log("forwardToExternalBot [{$clientName}/{$channel}]: forward failed — HTTP {$code} {$err}");
    }
}

function findIntegrationCreds(PDO $pdo, string $clientId, string $channel) {
    // channel is already resolved to an app_key by the caller (instagram|facebook|whatsapp).
    $appKey = in_array($channel, ['instagram', 'facebook', 'whatsapp', 'linkedin'], true) ? $channel : 'facebook';
    $stmt = $pdo->prepare("SELECT credentials FROM integrations WHERE client_id = :cid AND app_key = :k AND status = 'active' LIMIT 1");
    $stmt->execute([':cid' => $clientId, ':k' => $appKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
    if (empty($creds['access_token'])) return null;
    // WhatsApp creds carry phone_id instead of page_id.
    if ($appKey === 'whatsapp') { if (empty($creds['phone_id'])) return null; }
    elseif (empty($creds['page_id'])) return null;
    return $creds;
}

// Maps a reply-bot tone level to a concrete writing-style directive for Claude.
function toneDirective(string $tone): string {
    switch ($tone) {
        case 'slang':
            return "Tone level — Casual/Slang: write like texting a friend. Contractions, casual slang, emoji where natural, "
                 . "short punchy sentences. Skip formal greetings/sign-offs.";
        case 'formal':
            return "Tone level — Formal/Professional: polished business register. Full sentences, no slang or emoji, "
                 . "proper greetings/sign-offs, precise word choice.";
        default:
            return "Tone level — Friendly/Conversational: warm and approachable but still polished. Plain language, "
                 . "occasional light emoji is fine, no stiff corporate phrasing.";
    }
}

// Builds the Claude system prompt from Client Brain + reply-bot brain + reply history.
function buildReplyBotSystemPrompt(PDO $pdo, string $clientId, string $clientName, string $botBrain, bool $isComment = false, string $dontDo = '', string $tone = 'friendly', ?string $channel = null) {
    $parts = [];
    $parts[] = $isComment
        ? "You are the AI auto-reply assistant for {$clientName}'s social media inbox, built into SocialFlow. "
          . "Write ONE short, friendly reply to a PUBLIC comment on one of {$clientName}'s posts — one or two sentences max, no markdown, no headers. "
          . "This reply is visible to everyone, not just the commenter: never share prices, account numbers, order details, or other private info — "
          . "if that's needed, invite them to send a DM instead. Match this client's actual tone. Reply only with the message text itself, nothing else."
        : "You are the AI auto-reply assistant for {$clientName}'s social media inbox (Instagram/Messenger DMs), built into SocialFlow. "
          . "Write ONE short, natural reply to the customer's latest message below — a few sentences max, no markdown, no headers, "
          . "matching this client's actual tone and the way they typically reply. Reply only with the message text itself, nothing else.";

    // The agency-chosen tone level is a strict, sticky directive — every reply in every
    // conversation for this client must stay in this register, regardless of how the
    // customer writes to you.
    $parts[] = toneDirective($tone) . " Stay in this register for every reply, no matter what tone the customer uses.";

    // The customer's name, when known, is passed as the first line of the user message
    // (not in this thread's text), so address them by it naturally — never claim you
    // don't have it or ask the customer to repeat it if it's already been given.
    $parts[] = "If a customer name is provided to you, use it naturally to personalize your reply. If no name is provided, naturally ask for their name once, early in the conversation (e.g. \"By the way, who am I speaking with?\") woven into your reply — but check the conversation history first: if you can see you already asked for their name in an earlier message in this thread, do not ask again, just continue the conversation normally.";

    // Instagram story replies/mentions arrive tagged in the conversation text below
    // (e.g. "[Replied to your Instagram story] 🔥") since the bot has no way to see
    // the actual story image — acknowledge the context naturally without claiming
    // to know exactly what was in it.
    $parts[] = "If a customer message is tagged [Replied to your Instagram story] or [Mentioned you in their story], acknowledge that naturally (e.g. thank them for the reaction/mention) — but never claim to know the specific contents of the story, since you can't see it. Never repeat the bracketed tag itself in your reply.";

    // Comments carry the caption of the post/reel they're on (see generateBotReply) —
    // use it to tell apart very different comment contexts, most importantly hiring/
    // job posts vs. service-promo posts, since the right reply is completely different.
    $parts[] = "If you're given the caption of the post/reel a comment is on, use it to understand the context — e.g. a comment on a hiring/job post (someone applying or asking about the role) should get a reply about the application process, NOT a sales pitch about the agency's services. Reserve the services pitch for comments on posts that are actually promoting the agency's work.";

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

    // Hand-off rule: the customer never chooses who calls them or when.
    $parts[] = "Reply in the same language and dialect as the customer's latest message (Arabic stays Arabic, Egyptian stays Egyptian). Keep the reply short: at most about 70 words, and always finish your last sentence.";
    $parts[] = "Hand-off rule: never ask the customer to choose a team, a department or a specific person, and never ask which person to ask for, "
             . "or which day or time suits them for a call. When they want to talk to someone or you have their contact, just say that one of our team will contact them shortly. "
             . "Do not offer options about who or when.";

    // Our own agency inbox: explain the services properly instead of a one-line answer.
    if (stripos($clientName, 'admepro') !== false) {
        $parts[] = "When someone asks about our services, or says what they're interested in, give a useful, specific answer (3 to 5 short sentences, plain text, no markdown) before asking for anything: "
                 . "say what we actually do for that service and what they get, e.g. social media management (content planning, designs, reels, posting and community replies, monthly reporting), "
                 . "paid advertising (Meta/Google/TikTok campaigns, targeting, optimisation, performance reports), branding and creative design, video and reel production, websites and SEO. "
                 . "Never invent prices, packages, timelines or client names.";
        if (!$isComment) {
            $numberRule = $channel === 'whatsapp'
                ? "We already have their WhatsApp number: never ask for it."
                : "We do not have their phone number yet on this channel: after the three questions, ask ONCE for their phone/WhatsApp number so the team can reach them.";
            $parts[] = "SHORT QUALIFYING QUESTIONNAIRE. Use it only when the person is interested in our services (not job seekers, vendors or complaints). Learn what we need in three short questions, ONE question per message:\n"
                     . "1) Their business: what it is / its field.\n"
                     . "2) The service they need: list the options inline in one short line (social media, branding, video and AI content, ads, website).\n"
                     . "3) Their main goal or timing right now (for example more leads, a launch, awareness).\n"
                     . "Be smart: skip any question the customer already answered, even in their first message, and infer what you can. Never ask two questions in one message and never repeat a question. "
                     . "Start each reply by acknowledging their last answer in a few specific words (not a generic thanks). If they ask about a service, answer it briefly first (3 to 5 sentences), then ask the next question. "
                     . "If they ask for a price, say it depends on the scope and continue the questions: never invent numbers. If they decline or seem not interested, stop asking.\n"
                     . "When the three are answered (or skipped), close: thank them and say one of our team will contact them shortly with ideas. No choices about who or when, and no more questions. " . $numberRule;
        }
    }

    if (trim($botBrain) !== '') {
        $parts[] = "Specific instructions set by the agency for this client's auto-replies — follow these closely:\n" . trim($botBrain);
    }

    if (trim($dontDo) !== '') {
        $parts[] = "Hard rules — things you must NEVER do, no matter what the customer asks or how the conversation goes. "
                  . "These override every other instruction above if there's ever a conflict:\n" . trim($dontDo);
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
function generateBotReply(PDO $pdo, string $clientId, string $clientName, string $botBrain, array $threadMessages, bool $isComment = false, string $dontDo = '', string $tone = 'friendly', ?string $customerName = null, ?string $postCaption = null, ?string $channel = null) {
    $system = buildReplyBotSystemPrompt($pdo, $clientId, $clientName, $botBrain, $isComment, $dontDo, $tone, $channel);

    $convo = implode("\n", array_map(
        fn($m) => ($m['direction'] === 'in' ? 'Customer' : 'Agency') . ': ' . $m['message_text'],
        $threadMessages
    ));

    $label = $isComment ? 'Draft the next public reply comment from the agency.' : 'Draft the next reply from the agency.';
    $nameLine = $customerName ? "Customer's name: {$customerName}\n" : '';
    // For comments, the caption tells the bot what kind of post this is reacting to
    // (a hiring post needs a very different reply than a service-promo post or a
    // product reel) — without it, the bot only ever saw the comment text in isolation.
    $captionLine = $postCaption ? "Caption of the post/reel this comment is on:\n\"{$postCaption}\"\n\n" : '';
    $payload = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 1000,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => "{$nameLine}{$captionLine}Conversation so far:\n{$convo}\n\n{$label}"]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) {
        logBotActivity($pdo, $clientName, 'error', "HTTP {$status} from Claude");
        return null;
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    // If the model was cut off by the length limit, never send half a sentence: keep everything up to the last finished sentence.
    if (($data['stop_reason'] ?? '') === 'max_tokens' && $text !== '' && preg_match('/^(.*[.!?؟。\n])[^.!?؟。\n]*$/su', $text, $mm) && mb_strlen($mm[1]) > mb_strlen($text) * 0.4) $text = trim($mm[1]);
    if ($text === '' || $text === 'NEEDS_HUMAN') {
        logBotActivity($pdo, $clientName, 'success', $text === 'NEEDS_HUMAN' ? 'Escalated to a human — no auto-reply sent' : 'Empty reply, nothing sent');
        return null;
    }
    logBotActivity($pdo, $clientName, 'success', 'Drafted a reply (' . mb_strlen($text) . ' chars)');
    return $text;
}
// Surfaces the reply bot as a real, visible log on the AI Agents page (its
// card there was informational-only before this — now it has an actual
// activity trail, same [agent:*] tag convention agentAI() uses client-side.
function logBotActivity(PDO $pdo, string $clientName, string $status, string $detail) {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by, performed_at) VALUES (UUID(), :action, 'agents', :details, :status, 'agent', :now)");
        $stmt->execute([
            ':action' => "Bot: {$clientName}",
            ':details' => "[agent:reply_bot] {$detail}",
            ':status' => $status,
            ':now' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) { /* logging must never break the actual reply flow */ }
}

// Sends a DM reply via the Graph API (same shape as meta-send-reply.php).
function sendMetaDM(string $channel, string $pageId, string $accessToken, string $recipientId, string $message) {
    // Instagram-Login API tokens ("IGAA..." prefix) are scoped to graph.instagram.com —
    // graph.facebook.com rejects them with "Cannot parse access token". Page/Messenger
    // tokens still go through graph.facebook.com as before.
    $graph_host = ($channel === 'instagram' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $endpoint = "https://{$graph_host}/v19.0/{$pageId}/messages";
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

// Posts a public reply to a Facebook/Instagram comment via the Graph API.
function sendMetaCommentReply(string $channel, string $accessToken, string $commentId, string $message) {
    // Instagram-Login API tokens ("IGAA..." prefix) are scoped to graph.instagram.com —
    // same split as sendMetaDM().
    $graph_host = ($channel === 'ig_comment' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    // Facebook Page comments: POST /{comment-id}/comments creates a reply comment.
    // Instagram comments: POST /{ig-comment-id}/replies is the dedicated reply endpoint.
    $path = $channel === 'ig_comment' ? 'replies' : 'comments';
    // v23.0: replying to a Page-post comment maps to pages_manage_engagement.
    // The legacy v19.0 endpoint demanded the now-deprecated pages_read_user_content,
    // which Meta no longer issues (the OAuth dialog rejects it as an invalid scope).
    $endpoint = "https://{$graph_host}/v23.0/{$commentId}/{$path}";
    $post_data = ['message' => $message, 'access_token' => $accessToken];
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

// Sends a WhatsApp text reply via the WhatsApp Cloud API (same shape as whatsapp.php).
// $phoneId is the client's WhatsApp phone-number ID; $to is the customer's number.
// Named distinctly from pro-lib.php's sendWhatsAppReply() (the Pro-number sender),
// since both files are loaded together.
function sendWhatsAppBotReply(string $phoneId, string $accessToken, string $to, string $message) {
    $to = '+' . preg_replace('/\D/', '', $to); // E.164-ish, digits only
    $endpoint = "https://graph.facebook.com/v19.0/{$phoneId}/messages";
    $payload  = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $message],
    ]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer {$accessToken}"],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($curl_err) return [502, null];
    return [$http_code, json_decode($response, true)];
}

function storeBotMessage(PDO $pdo, $clientId, $clientName, $channel, $customerId, $customerName, $text, $draftStatus, $threadStatus, $externalId = null) {
    $stmt = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status, draft_status, external_id) VALUES (:cid, :cname, :ch, :custid, :custname, 'out', :txt, 'bot', :tstatus, :dstatus, :eid)");
    $stmt->execute([':cid'=>$clientId, ':cname'=>$clientName, ':ch'=>$channel, ':custid'=>$customerId, ':custname'=>$customerName, ':txt'=>$text, ':tstatus'=>$threadStatus, ':dstatus'=>$draftStatus, ':eid'=>$externalId]);
}

// Asks Claude whether an inbound message to admepro's OWN social/WhatsApp account
// (not a client's) signals interest in admepro's services — used to auto-capture
// leads from the agency's own inbox. Returns true/false; fails closed (false) on
// any API error so a glitch never spams the Leads page.
function isInterestedInOurServices(string $text) {
    $payload = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 10,
        'system' => "You classify inbound messages sent to a social media marketing agency's own Facebook/Instagram/WhatsApp account. "
                  . "Reply with exactly one word: YES if the message shows interest in hiring the agency or its services "
                  . "(asking about pricing, packages, social media management, content creation, ads, collaboration, etc.), "
                  . "or NO if it's spam, a job application, an existing client/team message, or unrelated chatter.",
        'messages' => [['role' => 'user', 'content' => $text]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) return false;
    $reply = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $reply .= $block['text'];
    }
    return stripos(trim($reply), 'yes') === 0;
}

// Condenses a lead's conversation into a short brief for the CRM notes and the
// WhatsApp notification, instead of dumping the raw (often multi-message) thread
// text — admins want "what are they asking about", not a transcript.
function summarizeLeadInquiry(string $combinedText) {
    $payload = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 150,
        'system' => "You summarize an inbound conversation to a social media marketing agency from a potential lead. "
                  . "Write a brief, at most 4 short lines, covering what they're asking about / interested in and any "
                  . "key details mentioned (budget, timeline, business type, etc). Plain text, no markdown, no headers, "
                  . "no greeting/preamble — just the brief itself.",
        'messages' => [['role' => 'user', 'content' => $combinedText]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) return null;
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return trim($text) ?: null;
}

/** Reads a whole conversation (customer + agency) and judges whether it is a QUALIFIED sales lead for admepro.
 *  Returns ['verdict' => qualified|maybe|not_qualified, 'score' => 0-100, 'reason', 'business', 'service', 'goal', 'missing' => [], 'brief'] or null when the AI call fails. */
function evaluateLeadQualification(string $dialog): ?array {
    $payload = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 500,
        'system' => "You judge whether a chat with a creative and digital marketing agency (admepro: social media management, branding, video and AI content, paid ads, websites) is a QUALIFIED sales lead.\n"
                  . "qualified: a real business or a serious project, a clear need that matches what we sell, a goal or reason to act, and they are engaging.\n"
                  . "maybe: interested but a key piece is missing (what the business is, which service, or the goal), or seriousness is unclear: a call is needed to decide.\n"
                  . "not_qualified: not a fit: personal or student use, job seeker, someone selling to us, spam or joking, wants only free work, wants something we do not offer, or clearly declined.\n"
                  . "Judge only what is in the conversation. Never invent facts. Reply with ONE JSON object only: "
                  . "{\"verdict\":\"qualified|maybe|not_qualified\",\"score\":0-100,\"reason\":\"one short sentence\",\"business\":\"\",\"service\":\"\",\"goal\":\"\",\"missing\":[\"short items\"],\"brief\":\"at most 4 short lines about what they want and key details\"}",
        'messages' => [['role' => 'user', 'content' => mb_substr($dialog, 0, 6000)]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) return null;
    $text = ''; foreach (($data['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    if (!preg_match('/\{.*\}/s', $text, $m)) return null;
    $j = json_decode($m[0], true); if (!is_array($j)) return null;
    $v = (string)($j['verdict'] ?? ''); if (!in_array($v, ['qualified', 'maybe', 'not_qualified'], true)) return null;
    return ['verdict' => $v, 'score' => max(0, min(100, (int)($j['score'] ?? 0))), 'reason' => mb_substr(trim((string)($j['reason'] ?? '')), 0, 220),
        'business' => mb_substr(trim((string)($j['business'] ?? '')), 0, 100), 'service' => mb_substr(trim((string)($j['service'] ?? '')), 0, 100), 'goal' => mb_substr(trim((string)($j['goal'] ?? '')), 0, 120),
        'missing' => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), (array)($j['missing'] ?? [])))), 0, 4), 'brief' => mb_substr(trim((string)($j['brief'] ?? '')), 0, 500)];
}

/** Saves the verdict on the lead: a "qual:<verdict>" tag and a "Qualification:" line in its notes. Alerts the assigned account manager the first time it becomes qualified. */
function applyLeadQualification(PDO $pdo, array $lead, array $q, string $tag, string $channel): void {
    $label = ['qualified' => 'QUALIFIED', 'maybe' => 'NEEDS INFO', 'not_qualified' => 'NOT QUALIFIED'][$q['verdict']];
    $line = "Qualification: {$label} · {$q['score']}/100 — " . ($q['reason'] !== '' ? $q['reason'] : 'no reason given') . ($q['missing'] ? ' · Missing: ' . implode(', ', $q['missing']) : '');
    $notes = (string)($lead['notes'] ?? '');
    $notes = preg_replace('/\n\nQualification: [^\n]*/u', '', $notes);
    $notes = preg_replace_callback('/\n\n' . preg_quote($tag, '/') . '/', fn($m) => "\n\n" . $line . $m[0], $notes, 1, $cnt);
    if (!$cnt) $notes .= "\n\n" . $line;
    $tags = json_decode((string)($lead['tags'] ?? '[]'), true); if (!is_array($tags)) $tags = [];
    $wasQualified = in_array('qual:qualified', $tags, true);
    $tags = array_values(array_filter($tags, fn($t) => strpos((string)$t, 'qual:') !== 0)); $tags[] = 'qual:' . $q['verdict'];
    try { $pdo->prepare("UPDATE leads SET notes = :n, tags = :t WHERE id = :id")->execute([':n' => $notes, ':t' => json_encode($tags), ':id' => $lead['id']]); }
    catch (\Throwable $e) { $pdo->prepare("UPDATE leads SET notes = :n WHERE id = :id")->execute([':n' => $notes, ':id' => $lead['id']]); }
    if ($q['verdict'] === 'qualified' && !$wasQualified && !empty($lead['assigned_to']) && function_exists('sendWhatsAppReply')) {
        $st = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE email = :e AND status = 'active' LIMIT 1"); $st->execute([':e' => $lead['assigned_to']]); $num = $st->fetchColumn();
        if ($num) {
            $bits = array_filter([$q['business'], $q['service'], $q['goal']]);
            sendWhatsAppReply($num, "🔥 Qualified lead: \"" . ($lead['name'] ?? '') . "\"" . (!empty($lead['phone']) ? " — " . $lead['phone'] : '') . ($bits ? "\n" . implode(' · ', $bits) : '') . "\nThey answered our questions and fit what we sell. Please call them now.");
        }
    }
}

// Creates a CRM lead from an inbound message expressing interest, whether it's
// addressed to admepro's own inbox or to a managed client's — a customer asking
// a client's Page/IG for a callback is just as much a real lead as one asking
// admepro directly, just attributed to that client via $clientId/$clientName.
// $phone is digits-only, passed only for WhatsApp (used as the lead's phone
// number). $clientId is needed to pull this sender's recent thread — interest
// and the phone number are often expressed across separate messages ("What is
// your services" then, later, just a bare phone number), so checking only the
// single latest message's text missed both: the phone-only message never reads
// as "interested" on its own, and a number given a message or two after the
// actual interest was never found at all.
function maybeCreateLeadFromMessage(PDO $pdo, string $channel, string $customerId, ?string $customerName, string $text, ?string $phone = null, ?string $clientId = null, ?string $clientName = null) {
    if (trim($text) === '') return;
    try {
        $threadTexts = [$text];
        if ($clientId) {
            $stmt = $pdo->prepare("SELECT message_text FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid AND direction = 'in' ORDER BY created_at DESC LIMIT 12");
            $stmt->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
            $threadTexts = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [$text];
        }
        $combinedText = implode("\n", array_reverse($threadTexts));

        // Try to pull a phone number out of the thread if the customer happened to
        // share one. A lead is only captured once a real contact number is found —
        // no phone means no lead, regardless of how interested they otherwise sound.
        if (!$phone && preg_match('/(\+?\d[\d\s\-\(\)]{7,}\d)/', $combinedText, $m)) {
            $phone = preg_replace('/[^\d+]/', '', $m[1]);
        }
        if (!$phone) return;

        // A message from a number that's already an existing client's own
        // contact number is never a new lead — without this, a client's rep
        // texting Pro's own number directly (not their dedicated inbox
        // number) could get captured as a fresh "lead" for their own agency.
        $clientMatch = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
        $clientMatch->execute([':p' => '%' . substr(preg_replace('/\D/', '', $phone), -9) . '%']);
        if ($clientMatch->fetchColumn()) return;

        $tag = "src_id:{$channel}:{$customerId}";
        $dupe = $pdo->prepare("SELECT id, name, phone, notes, tags, assigned_to FROM leads WHERE notes LIKE :tag LIMIT 1");
        $dupe->execute([':tag' => "%{$tag}%"]);
        if ($existing = $dupe->fetch(PDO::FETCH_ASSOC)) {
            // Already captured this sender before, but they may not have shared a phone
            // number yet at that point (e.g. first message was just "what are your
            // services", phone came a few messages later in the same thread) — backfill
            // it onto the existing lead instead of silently dropping it.
            if ($phone && empty($existing['phone'])) {
                $upd = $pdo->prepare("UPDATE leads SET phone = :phone WHERE id = :id");
                $upd->execute([':phone' => $phone, ':id' => $existing['id']]);
            }
            // The customer keeps answering the bot's short questionnaire after the lead was created: refresh the lead's brief from the whole
            // conversation (both sides), for the first few answers only, so the team sees business / service / goal without opening the inbox.
            try {
                if ($clientId && count($threadTexts) >= 2 && count($threadTexts) <= 6 && preg_match('/expressing interest in our services:\n/', (string)($existing['notes'] ?? ''))) {
                    $all = $pdo->prepare("SELECT direction, message_text FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid ORDER BY created_at ASC LIMIT 30");
                    $all->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
                    $dialog = implode("\n", array_map(fn($m) => ($m['direction'] === 'in' ? 'Customer: ' : 'Agency: ') . preg_replace('/\s+/', ' ', (string)$m['message_text']), $all->fetchAll(PDO::FETCH_ASSOC)));
                    $qual = evaluateLeadQualification($dialog);
                    $nb = ($qual['brief'] ?? '') !== '' ? $qual['brief'] : summarizeLeadInquiry($dialog);
                    if ($nb) {
                        $newNotes = preg_replace_callback('/(expressing interest in our services:\n).*?(\n\n(?:Qualification: [^\n]*\n\n)?' . preg_quote($tag, '/') . ')/s', fn($mm) => $mm[1] . $nb . $mm[2], (string)$existing['notes'], 1);
                        if ($newNotes && $newNotes !== $existing['notes']) { $pdo->prepare("UPDATE leads SET notes = :n WHERE id = :id")->execute([':n' => $newNotes, ':id' => $existing['id']]); $existing['notes'] = $newNotes; }
                    }
                    if ($qual) applyLeadQualification($pdo, $existing, $qual, $tag, $channel);
                }
            } catch (\Throwable $eN) { error_log('[reply-bot] lead brief refresh: ' . $eN->getMessage()); }
            return;
        }

        $brief = summarizeLeadInquiry($combinedText) ?: $combinedText;

        // Classify what kind of contact this actually is (lead / service_provider /
        // hiring) instead of always defaulting to "lead" — the brief text alone often
        // makes clear this is a job applicant or an outbound pitch, not a real lead.
        $classification = classifyClientContact($clientName ?: 'admepro', $combinedText);
        // Only skip capture on a CONFIRMED "other" or "hiring" classification
        // — a null classification means the AI call itself failed, which
        // shouldn't silently drop a real contact who did share a phone
        // number. "hiring" (someone asking about a job/vacancy) is never a
        // sales lead — capturing it here just pollutes the CRM pipeline and
        // fires a misleading "New Lead" WhatsApp alert to an AM for
        // something that belongs in Recruitment, not Leads.
        if ($classification && in_array($classification['category'], ['other', 'hiring', 'service_provider'], true)) {
            try {
                $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'leads', :details, 'success', 'system')")
                    ->execute([
                        ':action' => "Lead capture skipped (classified as {$classification['category']})",
                        ':details' => "channel={$channel} customer={$customerId} ({$customerName}), phone={$phone}",
                    ]);
            } catch (\Throwable $e2) { /* best-effort */ }
            // A job seeker or a service provider is not a sales lead, but the person who handles them still needs to know:
            // notify whoever is set for this category (Clients → admepro → Leads → notification numbers), ONCE per sender per 30 days.
            if ($clientId && in_array($classification['category'], ['hiring', 'service_provider'], true)) {
                try {
                    $notifyKey = "contact-notified:{$classification['category']}:{$channel}:{$customerId}";
                    $seenN = $pdo->prepare("SELECT 1 FROM activity_logs WHERE action = 'Contact notified' AND details LIKE :k AND created_at >= NOW() - INTERVAL 30 DAY LIMIT 1");
                    $seenN->execute([':k' => "%{$notifyKey}%"]);
                    if (!$seenN->fetchColumn()) {
                        notifyLeadCategorySubscriber($pdo, $clientId, $classification['category'], $customerName ?: 'Unknown (' . $channel . ')', $phone, $channel, (string)($classification['brief'] ?? $combinedText), $clientName ?: 'admepro');
                        $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Contact notified', 'leads', :d, 'success', 'system')")
                            ->execute([':d' => $notifyKey . " ({$customerName}, {$phone})"]);
                    }
                } catch (\Throwable $e3) { error_log('[reply-bot] contact notify: ' . $e3->getMessage()); }
            }
            return;
        }
        $category = $classification['category'] ?? 'lead';
        $rotationAM = $category === 'lead' ? assignLeadRotation($pdo) : null;

        $leadName = $customerName ?: 'Unknown (' . $channel . ')';
        $stmt = $pdo->prepare("INSERT INTO leads (name, phone, source, status, platforms, notes, category, client_id, client_name, assigned_to, assigned_at) VALUES (:name, :phone, :source, 'new', :platforms, :notes, :category, :cid, :cname, :assigned_to, :assigned_at)");
        $stmt->execute([
            ':name' => $leadName,
            ':phone' => $phone,
            ':source' => $channel === 'whatsapp' ? 'whatsapp' : $channel,
            ':platforms' => json_encode([$channel]),
            ':notes' => "Auto-captured by SocialFlow from an inbound {$channel} conversation" . ($clientName ? " on {$clientName}'s account" : '') . " expressing interest in our services:\n{$brief}\n\n{$tag}",
            ':category' => $category,
            ':cid' => $clientId,
            ':cname' => $clientName,
            ':assigned_to' => $rotationAM['email'] ?? null,
            ':assigned_at' => $rotationAM ? date('Y-m-d H:i:s') : null,
        ]);

        if ($clientId) notifyLeadCategorySubscriber($pdo, $clientId, $category, $leadName, $phone, $channel, $brief, $clientName);

        if ($rotationAM && !empty($rotationAM['id'])) {
            $amPhone = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id LIMIT 1");
            $amPhone->execute([':id' => $rotationAM['id']]);
            $waNum = $amPhone->fetchColumn();
            if ($waNum) sendWhatsAppReply($waNum, "New lead assigned to you: \"{$leadName}\" — {$phone}. It's your turn in rotation — take a look when you can.");
        }
    } catch (\Throwable $e) {
        error_log('maybeCreateLeadFromMessage EXCEPTION: ' . $e->getMessage());
        // Also written to the Activity Log (DB, admin-viewable in-app) —
        // error_log() alone turned out to be effectively invisible: no
        // error_log path is configured on this server, so failures here
        // were only ever findable by locating and grepping the web
        // server's own error log manually.
        try {
            $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Lead capture failed', 'leads', :details, 'error', 'system')")
                ->execute([':details' => "channel={$channel} customer={$customerId} ({$customerName}): " . $e->getMessage()]);
        } catch (\Throwable $e2) { /* best-effort */ }
    }
}

// WhatsApps whatever number is configured (per client + category) in the Leads
// tab's notification settings, with the new contact's full details — that's
// the only routing now; there's no separate blanket "every admin" broadcast,
// since that caused admins to get notified of every client's every category
// regardless of what they'd actually configured.
function notifyLeadCategorySubscriber(PDO $pdo, string $clientId, string $category, string $leadName, ?string $phone, string $channel, string $brief, ?string $clientName = null) {
    $raw = ''; $emailsRaw = '';
    try {
        $stmt = $pdo->prepare("SELECT whatsapp_number, emails FROM lead_notify_settings WHERE client_id = :cid AND category = :cat LIMIT 1");
        $stmt->execute([':cid' => $clientId, ':cat' => $category]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: []; $raw = (string)($row['whatsapp_number'] ?? ''); $emailsRaw = (string)($row['emails'] ?? '');
    } catch (\Throwable $e) {   // the 'emails' column is not there yet: WhatsApp keeps working as before
        $stmt = $pdo->prepare("SELECT whatsapp_number FROM lead_notify_settings WHERE client_id = :cid AND category = :cat LIMIT 1");
        $stmt->execute([':cid' => $clientId, ':cat' => $category]);
        $raw = (string)$stmt->fetchColumn();
    }
    if (!$raw && !$emailsRaw) return;
    // email recipients get the same details as the WhatsApp message
    if ($emailsRaw !== '' && !function_exists('authSendMail') && is_file(__DIR__ . '/auth-lib.php')) { try { require_once __DIR__ . '/auth-lib.php'; } catch (\Throwable $e) {} }
    if ($emailsRaw !== '' && function_exists('authSendMail')) {
        $catLabel = ['lead' => 'Lead', 'service_provider' => 'Service Provider', 'hiring' => 'Hiring'][$category] ?? ucfirst($category);
        $snip = mb_strlen($brief) > 800 ? mb_substr($brief, 0, 800) . '…' : $brief;
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#111"><h3 style="margin:0 0 8px">New ' . $h($catLabel) . ' captured' . ($clientName ? ' for ' . $h($clientName) : '') . '</h3>'
              . '<p><b>Name:</b> ' . $h($leadName) . '<br><b>Source:</b> ' . $h($channel) . ($phone ? '<br><b>Phone:</b> ' . $h($phone) : '') . '</p>'
              . '<p><b>Details:</b><br>' . nl2br($h($snip)) . '</p><p style="color:#666">Captured by SocialFlow. No message was sent to this contact.</p></div>';
        foreach (array_filter(array_map('trim', explode(',', $emailsRaw))) as $to) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) { try { authSendMail($to, 'New ' . $catLabel . ' captured' . ($clientName ? ' - ' . $clientName : ''), $html, 'SocialFlow'); } catch (\Throwable $e) {} }
        }
    }
    if (!$raw || !function_exists('sendWhatsAppReply')) return;
    // Multiple numbers for the same client+category are stored comma-separated
    // in the one row (e.g. "201001234567,201009876543").
    $numbers = array_filter(array_map('trim', explode(',', $raw)));
    if (!$numbers) return;
    $snippet = mb_strlen($brief) > 400 ? mb_substr($brief, 0, 400) . '…' : $brief;
    $categoryLabel = ['lead' => 'Lead', 'service_provider' => 'Service Provider', 'hiring' => 'Hiring'][$category] ?? ucfirst($category);
    $body = "New {$categoryLabel} contact captured by SocialFlow!\n\n"
          . "Name: {$leadName}\n"
          . ($clientName ? "Client: {$clientName}\n" : '')
          . "Channel: {$channel}" . ($phone ? " ({$phone})" : '') . "\n\n"
          . "Details:\n{$snippet}\n\n"
          . "View it on the CRM Leads page.";
    foreach ($numbers as $number) {
        sendWhatsAppReply($number, $body);
    }
}

// Classifies an inbound message to a MANAGED CLIENT's own inbox (not admepro's) —
// distinct from isInterestedInOurServices() above, which asks "does this person
// want to hire admepro". Here the question is "is this person a customer lead,
// a service provider pitching to the client, or a job applicant" for that
// client's own business. Returns null for "other" (spam/support/small talk —
// not worth capturing) or on any API failure.
function classifyClientContact(string $clientName, string $combinedText) {
    // admepro's own inbox is a special case worth spelling out explicitly —
    // a company asking to HIRE admepro as their marketing/social media
    // agency ("looking for a marketing partner", "send us your portfolio",
    // "interested in collaborating with your agency") is a real LEAD, even
    // though the wording ("partner"/"collaborate") sounds two-way and was
    // getting misread as the opposite: them pitching THEIR OWN service to
    // admepro (service_provider). Only an actual freelancer/vendor/supplier
    // offering a service TO admepro is service_provider here.
    $ownInboxNote = strcasecmp($clientName, 'admepro') === 0
        ? "\n\nSpecial case for admepro's own inbox: someone asking to hire admepro as their marketing/social media agency — including phrasing like \"looking for a marketing partner\", \"interested in collaborating\", or \"send us your portfolio/profile\" — is a LEAD (they want to buy admepro's services), NOT a service_provider, even though that wording sounds two-way. Only classify as service_provider if they're clearly offering THEIR OWN distinct service/product TO admepro (a freelancer, supplier, or another agency pitching admepro)."
        : "";
    $payload = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 200,
        'system' => "You classify inbound messages sent to {$clientName}'s social media / WhatsApp inbox. "
                  . "Reply with EXACTLY this format and nothing else:\n"
                  . "CATEGORY: lead|service_provider|hiring|other\n"
                  . "BRIEF: <one short sentence summarizing what they want>\n\n"
                  . "lead = a potential customer interested in {$clientName}'s own products/services (asking prices, availability, how to order/book).\n"
                  . "service_provider = someone offering THEIR OWN service/product/collaboration TO {$clientName} (a supplier, freelancer, agency, influencer pitching).\n"
                  . "hiring = someone applying for a job or asking about employment/vacancies at {$clientName}.\n"
                  . "other = anything else not worth capturing as a contact (spam, random chat, an existing customer's support issue, etc)."
                  . $ownInboxNote,
        'messages' => [['role' => 'user', 'content' => $combinedText]],
    ];
    [$status, $data] = callClaude($payload);
    if ($status < 200 || $status >= 300) return null; // genuine API failure — caller can't tell this apart from "other" otherwise
    $text = '';
    foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $text .= $block['text']; }
    if (!preg_match('/CATEGORY:\s*(\w+)/i', $text, $m1)) return null;
    $category = strtolower(trim($m1[1]));
    // 'other' is a real, valid classification (spam, random chat, an
    // existing customer's support issue, a TEAM MEMBER just messaging Pro on
    // the same number) — it must be returned, not swallowed into null,
    // otherwise callers that fall back to 'lead' on null can't distinguish
    // "classification genuinely failed" from "this was correctly classified
    // as NOT a lead", and end up capturing every non-lead as a lead anyway.
    if (!in_array($category, ['lead', 'service_provider', 'hiring', 'other'], true)) return null;
    $brief = null;
    if (preg_match('/BRIEF:\s*(.+)/is', $text, $m2)) $brief = trim($m2[1]);
    return ['category' => $category, 'brief' => $brief];
}

// Round-robin lead assignment (Settings → Leads → Lead Rotation Settings,
// stored as app_settings.lead_rotation_settings). Called right before a new
// lead is inserted so it lands pre-assigned instead of sitting unowned.
// Skips anyone currently sitting on an already-overdue (rotation_missed)
// lead, so a slow responder doesn't keep piling up new ones while behind —
// falls back to the next-in-line if literally everyone is behind (still
// need to assign it to SOMEONE). Returns null if rotation is off/unconfigured.
function assignLeadRotation(PDO $pdo) {
    $row = $pdo->query("SELECT id, lead_rotation_settings FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $cfg = json_decode($row['lead_rotation_settings'] ?? '{}', true) ?: [];
    if (empty($cfg['enabled']) || empty($cfg['rotation_order'])) return null;

    $order = array_values($cfg['rotation_order']);
    $n = count($order);
    if ($n === 0) return null;
    $pointer = ((int)($cfg['rotation_pointer'] ?? 0)) % $n;

    $overdueStmt = $pdo->prepare("SELECT 1 FROM leads WHERE assigned_to = :email AND rotation_missed = 1 AND status NOT IN ('closed_won','closed_lost') LIMIT 1");
    $amStmt = $pdo->prepare("SELECT id, name, email FROM team_members WHERE id = :id AND status = 'active' LIMIT 1");

    $chosen = null;
    for ($i = 0; $i < $n; $i++) {
        $idx = ($pointer + $i) % $n;
        $amStmt->execute([':id' => $order[$idx]]);
        $am = $amStmt->fetch(PDO::FETCH_ASSOC);
        if (!$am || !$am['email']) continue;
        $overdueStmt->execute([':email' => $am['email']]);
        $hasOverdue = (bool) $overdueStmt->fetchColumn();
        if (!$hasOverdue) { $chosen = $am; $pointer = $idx; break; }
        if (!$chosen) $chosen = $am; // fallback: everyone's behind, still have to assign it to someone
    }
    if (!$chosen) return null;

    $pdo->prepare("UPDATE app_settings SET lead_rotation_settings = JSON_SET(lead_rotation_settings, '$.rotation_pointer', :p) WHERE id = :id")
        ->execute([':p' => ($pointer + 1) % $n, ':id' => $row['id']]);

    return $chosen;
}

// Captures a contact from a MANAGED CLIENT's own inbox — called for every
// client (unlike maybeCreateLeadFromMessage, which is admepro-own-inbox-only)
// right after an inbound message is stored. Classifies the sender via
// classifyClientContact() and, if it's a lead/service_provider/hiring
// contact, saves it to the leads table tagged with this client_id so it
// shows up on that client's "Leads" tab. Dedupes on the same src_id tag
// pattern used by maybeCreateLeadFromMessage.
function maybeCaptureClientContact(PDO $pdo, string $channel, string $customerId, ?string $customerName, string $text, string $clientId, string $clientName, ?string $phone = null) {
    if (trim($text) === '') return;
    try {
        $stmt = $pdo->prepare("SELECT message_text FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid AND direction = 'in' ORDER BY created_at DESC LIMIT 12");
        $stmt->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
        $threadTexts = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [$text];
        $combinedText = implode("\n", array_reverse($threadTexts));

        if (!$phone && preg_match('/(\+?\d[\d\s\-\(\)]{7,}\d)/', $combinedText, $m)) {
            $phone = preg_replace('/[^\d+]/', '', $m[1]);
        }
        // A lead must have a real contact number — no phone, no capture (also
        // saves a Claude classification call for threads that could never
        // qualify anyway).
        if (!$phone) return;

        $tag = "src_id:{$channel}:{$customerId}";
        $dupe = $pdo->prepare("SELECT id, phone FROM leads WHERE client_id = :cid AND notes LIKE :tag LIMIT 1");
        $dupe->execute([':cid' => $clientId, ':tag' => "%{$tag}%"]);
        if ($existing = $dupe->fetch(PDO::FETCH_ASSOC)) {
            if (empty($existing['phone'])) {
                $pdo->prepare("UPDATE leads SET phone = :phone WHERE id = :id")->execute([':phone' => $phone, ':id' => $existing['id']]);
            }
            return;
        }

        // A real phone number was shared — that alone is worth capturing even if
        // there isn't enough context to classify it (a bare number with no
        // message text, for instance). Default to "lead" in that case rather
        // than silently dropping a real contact. But a CONFIRMED "other"
        // classification (spam, random chat, an existing customer's support
        // issue) must actually skip capture — only a null (failed) classification
        // falls back to "lead".
        $classification = classifyClientContact($clientName, $combinedText);
        if ($classification && $classification['category'] === 'other') return;
        $category = $classification['category'] ?? 'lead';
        $brief = $classification['brief'] ?? $combinedText;

        $leadName = $customerName ?: 'Unknown (' . $channel . ')';
        $stmt = $pdo->prepare("INSERT INTO leads (name, phone, source, status, platforms, notes, client_id, client_name, category) VALUES (:name, :phone, :source, 'new', :platforms, :notes, :cid, :cname, :category)");
        $stmt->execute([
            ':name' => $leadName,
            ':phone' => $phone,
            ':source' => $channel === 'whatsapp' ? 'whatsapp' : $channel,
            ':platforms' => json_encode([$channel]),
            ':notes' => "Auto-captured by SocialFlow from {$clientName}'s {$channel} inbox:\n{$brief}\n\n{$tag}",
            ':cid' => $clientId,
            ':cname' => $clientName,
            ':category' => $category,
        ]);

        notifyLeadCategorySubscriber($pdo, $clientId, $category, $leadName, $phone, $channel, $brief, $clientName);
    } catch (\Throwable $e) {
        error_log('maybeCaptureClientContact EXCEPTION: ' . $e->getMessage());
    }
}

// Entry point called from meta-inbox-webhook.php right after an inbound message is stored.
// Looks up this client's reply-bot settings, and if enabled for this channel, drafts a
// reply and either sends it immediately (mode=auto) or stores it as a pending draft
// for a team member to review (mode=approve). $externalId is the comment_id for
// comment channels (fb_comment/ig_comment) — null for DM channels.
/** Customers often send several messages in a row and each one calls this. Wait briefly so the burst lands, then let exactly ONE run answer all of it. */
function maybeAutoReply(PDO $pdo, string $clientId, string $clientName, string $channel, string $customerId, ?string $customerName, ?string $externalId = null, ?string $postCaption = null) {
    $isComment = in_array($channel, ['fb_comment', 'ig_comment', 'li_comment'], true);
    $s = $isComment ? null : getReplyBotSettings($pdo, $clientId);
    if ($isComment || !$s || !empty($s['external_bot']) || empty($s['enabled'])) { maybeAutoReplyOnce($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $externalId, $postCaption); return; }
    sleep(4);
    $lockName = 'sfbot_' . substr(md5($clientId . '|' . $channel . '|' . $customerId), 0, 24);
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 25)")->fetchColumn(); } catch (Throwable $e) { $got = 1; /* no lock support: carry on, the check below still prevents most doubles */ }
    if (!$got) { error_log("reply-bot SKIP [{$clientName}/{$channel}/{$customerId}]: another reply for this customer is running"); return; }
    try {
        $q = $pdo->prepare("SELECT MAX(CASE WHEN direction = 'in' THEN created_at END) i, MAX(CASE WHEN direction = 'out' THEN created_at END) o FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid");
        $q->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]); $t = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($t['o']) && !empty($t['i']) && strtotime((string)$t['o']) >= strtotime((string)$t['i'])) { error_log("reply-bot SKIP [{$clientName}/{$channel}/{$customerId}]: this burst of messages was already answered"); return; }
        maybeAutoReplyOnce($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $externalId, $postCaption);
    } finally { try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")"); } catch (Throwable $e) {} }
}

function maybeAutoReplyOnce(PDO $pdo, string $clientId, string $clientName, string $channel, string $customerId, ?string $customerName, ?string $externalId = null, ?string $postCaption = null) {
    $log = function (string $reason) use ($clientName, $channel, $customerId) {
        error_log("reply-bot SKIP [{$clientName}/{$channel}/{$customerId}]: {$reason}");
    };

    $settings = getReplyBotSettings($pdo, $clientId);
    if (!$settings) { $log('no settings row'); return; }
    // This client runs their own reply bot on their own system — rather than
    // that system needing its own separate Meta app/webhook subscription, it
    // connects THROUGH SocialFlow: we forward every inbound message to their
    // configured webhook URL (signed with their per-client key), and they
    // call external-bot-reply.php to actually send the reply, using our
    // connected Meta credentials (never handed to them directly). Checked
    // before the `enabled` check below (and independent of it) so this works
    // whether or not our own bot is separately toggled on — the two are
    // mutually exclusive by design, only one can ever reply to a customer.
    if (!empty($settings['external_bot'])) {
        $log('external bot — forwarding to client system, not replying ourselves');
        forwardToExternalBot($pdo, $settings, $clientId, $clientName, $channel, $customerId, $customerName, $externalId, $postCaption);
        return;
    }
    if (!$settings['enabled']) { $log('bot disabled'); return; }
    if (!in_array($channel, $settings['channels'], true)) { $log('channel not enabled in settings: ' . json_encode($settings['channels'])); return; }

    $isComment   = $channel === 'fb_comment' || $channel === 'ig_comment' || $channel === 'li_comment';
    $isWhatsApp  = $channel === 'whatsapp';
    if ($isComment && !$externalId) { $log('comment channel but no external_id'); return; }

    $stmt = $pdo->prepare("SELECT direction, message_text FROM customer_messages WHERE client_id = :cid AND channel = :ch AND customer_id = :custid ORDER BY created_at DESC LIMIT 12");
    $stmt->execute([':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
    $thread = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$thread) { $log('empty thread'); return; }

    $reply = generateBotReply($pdo, $clientId, $clientName, (string)($settings['brain'] ?? ''), $thread, $isComment, (string)($settings['dont_do'] ?? ''), (string)($settings['tone'] ?? 'friendly'), $customerName, $postCaption, $channel);
    $usedFallback = false;
    if (!$reply) {
        // Claude opted out (NEEDS_HUMAN) or the API call failed — rather than
        // leaving the customer with silence, send a configurable fallback
        // (set per-client in Reply Bot settings) so they at least know their
        // message was received and how to reach a human. The thread is still
        // flagged needs_human either way so the team follows up.
        $fallback = trim((string)($settings['fallback_message'] ?? ''));
        $lastInText = ''; foreach (array_reverse($thread) as $m) { if ($m['direction'] === 'in') { $lastInText = (string)$m['message_text']; break; } }
        $ar = (bool)preg_match('/\p{Arabic}/u', $lastInText);   // an Arabic customer gets an Arabic fallback
        if ($fallback === '') {
            $fallback = $ar
                ? ($customerName ? "شكراً لتواصلك معنا! عشان نساعدك بسرعة، ممكن تبعتلنا رقم موبايلك أو واتساب وفريقنا هيتواصل معاك قريب. 😊" : "شكراً لتواصلك معنا! ممكن تبعتلنا اسمك ورقم موبايلك أو واتساب عشان فريقنا يتواصل معاك قريب؟ 😊")
                : ($customerName
                    ? "Thanks for reaching out! For a quick answer, please share your phone/WhatsApp number or contact us directly, and our team will get back to you shortly. 😊"
                    : "Thanks for reaching out! Could you share your name and phone/WhatsApp number so our team can get back to you shortly? 😊");
        }
        $reply = $fallback;
        $usedFallback = true;

        // Don't repeat the exact same "please share your number" ask if the
        // customer already gave one and our last message was this same fallback —
        // that just loops. Acknowledge instead.
        $lastOut = null;
        foreach (array_reverse($thread) as $m) { if ($m['direction'] === 'out') { $lastOut = trim((string)$m['message_text']); break; } }
        $inboundText = implode("\n", array_column(array_filter($thread, fn($m) => $m['direction'] === 'in'), 'message_text'));
        $hasPhone = preg_match('/(\+?\d[\d\s\-\(\)]{7,}\d)/', $inboundText);
        if ($hasPhone && $lastOut === trim($fallback)) {
            $reply = $ar ? "شكراً لمشاركة رقمك! فريقنا هيتواصل معاك قريب. 😊" : "Thanks for sharing your number! Our team will reach out to you shortly. 😊";
        }
        $log('generateBotReply returned empty (Claude opted out or API error) — using fallback message');
    }

    // LinkedIn comments (through Zernio) always wait for a person's approval for now, whatever the client's mode.
    if ($settings['mode'] === 'auto' && $channel !== 'li_comment') {
        // Resolve the channel to the integration app_key that holds the send credentials.
        $appKey = $isWhatsApp ? 'whatsapp' : ($isComment ? ($channel === 'ig_comment' ? 'instagram' : 'facebook') : $channel);
        $creds = findIntegrationCreds($pdo, $clientId, $appKey);
        if (!$creds) { $log('no integration credentials found'); return; } // no credentials to send with — drop rather than fake a sent message
        if ($isComment) {
            [$httpCode, $body] = sendMetaCommentReply($channel, $creds['access_token'], $externalId, $reply);
        } elseif ($isWhatsApp) {
            [$httpCode, $body] = sendWhatsAppBotReply($creds['phone_id'], $creds['access_token'], $customerId, $reply);
        } else {
            [$httpCode, $body] = sendMetaDM($channel, $creds['page_id'], $creds['access_token'], $customerId, $reply);
        }
        if ($httpCode < 200 || $httpCode >= 300) { $log("Graph API send failed, http {$httpCode}: " . json_encode($body)); return; } // send failed — don't record a phantom "sent" message
        // A fallback message doesn't actually resolve the customer's question —
        // keep the thread flagged needs_human so the team still follows up,
        // even though something was sent.
        storeBotMessage($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $reply, 'sent', $usedFallback ? 'needs_human' : 'bot_handled', $externalId);
    } else {
        storeBotMessage($pdo, $clientId, $clientName, $channel, $customerId, $customerName, $reply, 'pending_review', 'needs_human', $externalId);
    }
}
