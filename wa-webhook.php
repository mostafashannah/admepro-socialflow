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
if (!$message || ($message['type'] ?? '') !== 'text') {
    exit; // ignore statuses, non-text messages, etc.
}

$phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? ''); // which of our numbers received it
$from = preg_replace('/\D/', '', $message['from'] ?? ''); // digits only, no '+'
$text = trim($message['text']['body'] ?? '');
if (!$from || !$text) exit;
$contactName = $value['contacts'][0]['profile']['name'] ?? null; // customer display name, if sent

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Route by which WhatsApp number received the message. The "Pro" assistant runs on
    // its own dedicated number (WA_PHONE_ID). Every OTHER connected number belongs to a
    // client's customer inbox — those messages are stored and handed to the reply bot,
    // exactly like Messenger/Instagram, instead of going to Pro.
    $proPhoneId = defined('WA_PHONE_ID') ? (string)WA_PHONE_ID : '';
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
            try { maybeAutoReply($pdo, $match['client_id'], $match['client_name'], 'whatsapp', $from, $contactName); }
            catch (\Throwable $e) { error_log('[wa-webhook] reply-bot EXCEPTION: ' . $e->getMessage()); }
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
    $reply = askPro($pdo, $senderName, $senderRole, $contextBlock, $text, $senderId);
    if ($reply) sendWhatsAppReply($from, $reply);
} catch (Throwable $e) {
    error_log('[wa-webhook] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(), 3, '/var/www/socialflow/pro-error.log');
}
exit;
