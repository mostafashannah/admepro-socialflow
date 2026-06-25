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

$body = json_decode($raw, true);
$message = $body['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
if (!$message || $message['type'] !== 'text') {
    exit; // ignore statuses, non-text messages, etc.
}

$from = preg_replace('/\D/', '', $message['from'] ?? ''); // digits only, no '+'
$text = trim($message['text']['body'] ?? '');
if (!$from || !$text) exit;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    [$senderName, $senderRole, $contextBlock, $senderId] = identifySender($pdo, $from);

    $reply = askPro($pdo, $senderName, $senderRole, $contextBlock, $text, $senderId);
    if ($reply) sendWhatsAppReply($from, $reply);
} catch (Throwable $e) {
    error_log('[wa-webhook] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(), 3, '/var/www/socialflow/pro-error.log');
}
exit;
