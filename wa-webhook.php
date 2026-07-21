<?php
// ================================================================
// SocialFlow — Inbound WhatsApp webhook ("Pro" via WhatsApp)
//
// Lets team members and clients message the SocialFlow number on WhatsApp
// and get a reply from "Pro" (the same assistant persona used in-app),
// with light context pulled from the live MySQL DB (who they are, their
// open tasks / pending approvals). Team members additionally get Claude
// tool-use access to look up clients and search tasks by stage/keyword/
// client (see pro-lib.php), so they can ask broader questions ("what
// clients do we have", "what stage is the Acme reel in"). This is a
// stateless MVP — each incoming message is answered independently; it
// does not carry chat history across messages.
//
// Setup in Meta App Dashboard → WhatsApp → Configuration:
//   Callback URL: https://yourdomain.com/wa-webhook.php
//   Verify token: same value as WA_VERIFY_TOKEN in config.php
//   Subscribe to the "messages" webhook field.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php';

// ---- 1. Verification handshake (GET) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && hash_equals(WA_VERIFY_TOKEN, (string)$token)) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ---- 2. Verify Meta's signature on the raw body ----
$raw = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET);
if (!$signatureHeader || !hash_equals($expected, $signatureHeader)) {
    http_response_code(403);
    exit;
}

// Acknowledge immediately so Meta doesn't retry/timeout; do the work after.
http_response_code(200);
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$body    = json_decode($raw, true);
$value   = $body['entry'][0]['changes'][0]['value'] ?? [];
$message = $value['messages'][0] ?? null;
$msgType = $message['type'] ?? '';
if (!$message || !in_array($msgType, ['text', 'audio', 'image'], true)) {
    exit; // ignore statuses, non-text/audio/image messages, etc.
}

$phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? ''); // which of our numbers received it
$from = preg_replace('/\D/', '', $message['from'] ?? ''); // digits only, no '+'
$contactName = $value['contacts'][0]['profile']['name'] ?? null; // customer display name, if sent
$isVoiceNote = false;
$imageBase64 = null;
$imageMime = null;

if ($msgType === 'audio') {
    // Voice notes only make sense on the Pro number (client inbox numbers
    // don't run the transcription/HR pipeline) — resolved for real just
    // below once $proPhoneId is known; bail early here if this is clearly
    // not a Pro-bound audio message.
    require_once __DIR__ . '/pro-lib.php';
    $mediaId = $message['audio']['id'] ?? '';
    if (!$from || !$mediaId) exit;
    [$bytes, $mime] = downloadWhatsAppMedia($mediaId);
    if (!$bytes) exit;
    $transcript = transcribeAudio($bytes, $mime);
    if (!$transcript) {
        // Transcription unavailable (OPENAI_API_KEY not configured, or the
        // API call failed) — tell the sender instead of silently dropping.
        if (function_exists('sendWhatsAppReply')) {
            sendWhatsAppReply($from, "Sorry, I couldn't transcribe that voice note right now — please type your message instead.");
        }
        exit;
    }
    $text = $transcript;
    $isVoiceNote = true;
} elseif ($msgType === 'image') {
    // Photos only make sense on the Pro number too — same reasoning as
    // voice notes above (client inbox numbers go through the reply bot,
    // never Pro/tool-use).
    require_once __DIR__ . '/pro-lib.php';
    $mediaId = $message['image']['id'] ?? '';
    if (!$from || !$mediaId) exit;
    [$bytes, $mime] = downloadWhatsAppMedia($mediaId);
    if (!$bytes) exit;
    $imageBase64 = base64_encode($bytes);
    $imageMime = $mime ?: 'image/jpeg';
    // Uploaded immediately (not only if/when a transaction eventually gets
    // logged) so the URL survives into conversation history as plain text —
    // the actual add_transaction call often happens on a LATER, image-less
    // turn (e.g. after "yes cash"), by which point this image is long gone
    // from the request; the URL embedded in history is what lets that later
    // turn still attach the receipt.
    $receiptUrl = saveReceiptImage($bytes, $imageMime);
    // A caption becomes the accompanying text; without one, give Pro a
    // generic instruction so it still has something to "answer" alongside
    // the image rather than an empty user turn.
    $caption = trim($message['image']['caption'] ?? '');
    $text = ($caption !== '' ? $caption : "Here's a photo — take a look and help with whatever it's for.")
        . ($receiptUrl ? "\n[photo_url: {$receiptUrl}]" : '');
} else {
    $text = trim($message['text']['body'] ?? '');
}

if (!$from || !$text) exit;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Meta can redeliver the exact same webhook message more than once
    // (slow ack, network retry) — without this check, Pro would reprocess
    // it and re-run tool calls like add_transaction, creating duplicate
    // expense/income rows. WhatsApp's own message id is globally unique,
    // so use it as an idempotency key: if we've already recorded it,
    // this is a redelivery — stop here before doing any work.
    $waMessageId = (string)($message['id'] ?? '');
    if ($waMessageId !== '') {
        try {
            $pdo->prepare("INSERT INTO wa_processed_messages (message_id) VALUES (:mid)")->execute([':mid' => $waMessageId]);
        } catch (Throwable $e) {
            // Only a genuine duplicate-key violation (code 23000, the
            // message_id primary key already exists) means "already
            // processed — skip it". Any other DB error here (e.g. the
            // migration hasn't been run yet and the table doesn't exist)
            // must NOT silently drop every message — log it and continue
            // processing normally instead.
            if ($e->getCode() === '23000') exit;
            error_log('[wa-webhook] wa_processed_messages check failed (continuing anyway): ' . $e->getMessage());
        }
    }

    // Route by which WhatsApp number received the message. The "Pro" assistant runs on
    // its own dedicated number (WA_PHONE_ID). Every OTHER connected number belongs to a
    // client's customer inbox — those messages are stored and handed to the reply bot,
    // exactly like Messenger/Instagram, instead of going to Pro.
    $proPhoneId = defined('WA_PHONE_ID') ? (string)WA_PHONE_ID : '';
    error_log("[wa-webhook] routing: incoming phone_number_id={$phoneNumberId} configured WA_PHONE_ID={$proPhoneId} from={$from}");
    if ($msgType === 'image' && $phoneNumberId !== $proPhoneId) {
        // Photo capture is a Pro-only capability — a customer sending a
        // photo to a client's own inbox number still just gets ignored,
        // same as before, instead of the reply bot firing off a reply to
        // a generic placeholder caption.
        exit;
    }
    if ($phoneNumberId && $phoneNumberId !== $proPhoneId) {
        // Match the receiving number to a client's WhatsApp integration.
        $rows = $pdo->query("SELECT client_id, client_name, credentials FROM integrations WHERE app_key='whatsapp' AND status='active' AND client_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $match = null;
        foreach ($rows as $row) {
            $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
            if (($creds['phone_id'] ?? '') === $phoneNumberId) { $match = $row; break; }
        }
        if ($match) {
            require_once __DIR__ . '/reply-bot-lib.php';
            $ins = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status) VALUES (:cid,:cname,'whatsapp',:custid,:custname,'in',:txt,'customer','needs_human')");
            $ins->execute([':cid'=>$match['client_id'], ':cname'=>$match['client_name'], ':custid'=>$from, ':custname'=>$contactName, ':txt'=>$text]);
            try {
                maybeCaptureClientContact($pdo, 'whatsapp', $from, $contactName, $text, $match['client_id'], $match['client_name'], $from);
                maybeAutoReply($pdo, $match['client_id'], $match['client_name'], 'whatsapp', $from, $contactName);
            } catch (\Throwable $e) { error_log('[wa-webhook] reply-bot EXCEPTION: ' . $e->getMessage()); }
        }
        exit; // handled (or no matching client) — do not fall through to Pro
    }

    // ---- Pro assistant path (the dedicated Pro number) ----
    [$senderName, $senderRole, $contextBlock, $senderId] = identifySender($pdo, $from);
    if (!$senderName) {
        // Not a known team member or client — this is an outside number messaging
        // admepro directly. Check if it's a prospect interested in our services
        // and capture it as a lead before replying as Pro.
        require_once __DIR__ . '/reply-bot-lib.php';
        try { maybeCreateLeadFromMessage($pdo, 'whatsapp', $from, $contactName, $text, $from); }
        catch (\Throwable $e) { error_log('[wa-webhook] lead-capture EXCEPTION: ' . $e->getMessage()); }
    }
    error_log("[wa-webhook] routing to Pro: senderName=" . var_export($senderName, true) . " senderRole=" . var_export($senderRole, true));
    $reply = askPro($pdo, $senderName, $senderRole, $contextBlock, $text, $senderId, $isVoiceNote ? $text : null, $from, $imageBase64, $imageMime);
    error_log("[wa-webhook] askPro returned: " . var_export($reply, true));
    if ($reply) sendWhatsAppReply($from, $reply);
} catch (Throwable $e) {
    error_log('[wa-webhook] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(), 3, '/var/www/socialflow/pro-error.log');
}
exit;
