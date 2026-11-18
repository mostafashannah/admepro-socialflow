<?php
// ================================================================
// Receives Zernio webhooks (comment.received for LinkedIn) and stores them in the same inbox the community agent
// uses, as channel "li_comment". Replies go back out through Zernio (see reply-bot-lib.php / meta-send-reply.php).
// Setup: add ZERNIO_WEBHOOK_SECRET to config.php, then in Zernio create a webhook for the event comment.received
// pointing at https://socialflow.admepro.com/zernio-webhook.php with that same secret.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reply-bot-lib.php';
require_once __DIR__ . '/zernio-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input');
$secret = defined('ZERNIO_WEBHOOK_SECRET') ? ZERNIO_WEBHOOK_SECRET : '';
$sig = $_SERVER['HTTP_X_ZERNIO_SIGNATURE'] ?? '';
if (!$secret || !$sig || !hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($sig))) { http_response_code(401); exit; }

$body = json_decode($raw, true);
http_response_code(200); echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); // answer Zernio within its 5 seconds, then work
set_time_limit(0);
if (!is_array($body)) exit;
$event = $body['event'] ?? ($_SERVER['HTTP_X_ZERNIO_EVENT'] ?? '');
if ($event === 'webhook.test') { error_log('[zernio-webhook] test event received'); exit; }
if ($event !== 'comment.received') exit;

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $c = $body['comment'] ?? []; $accountId = $body['account']['accountId'] ?? '';
    if (($c['platform'] ?? '') !== 'linkedin' || !$accountId) exit;                 // LinkedIn only for now
    if (!empty($c['author']['isOwnAccount'])) exit;                                 // never answer ourselves
    $commentId = (string)($c['id'] ?? ''); $postUrn = (string)($c['platformPostId'] ?? ''); $text = trim((string)($c['text'] ?? ''));
    if ($commentId === '' || $postUrn === '' || $text === '') exit;
    $externalId = $postUrn . '||' . $commentId;
    if (mb_strlen($externalId) > 255) exit;

    // Which client owns this Zernio account?
    $client = null;
    $st = $pdo->query("SELECT client_id, client_name, credentials FROM integrations WHERE status='active' AND app_key='linkedin'");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $cr = json_decode($row['credentials'] ?? '{}', true) ?: [];
        if (($cr['access_token'] ?? '') === 'zernio' && ($cr['page_id'] ?? '') === $accountId) { $client = $row; break; }
    }
    if (!$client || !$client['client_id']) { error_log('[zernio-webhook] no client for account ' . $accountId); exit; }

    // De-duplicate (Zernio can deliver the same event more than once).
    $d = $pdo->prepare("SELECT 1 FROM customer_messages WHERE client_id = ? AND channel = 'li_comment' AND external_id = ? LIMIT 1");
    $d->execute([$client['client_id'], $externalId]);
    if ($d->fetchColumn()) exit;

    $fromId = (string)($c['author']['id'] ?? $c['author']['username'] ?? 'linkedin-user');
    $fromName = $c['author']['name'] ?? ($c['author']['username'] ?? null);
    $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status, external_id) VALUES (?, ?, 'li_comment', ?, ?, 'in', ?, 'customer', 'needs_human', ?)")
        ->execute([$client['client_id'], $client['client_name'], $fromId, $fromName, $text, $externalId]);
    try { maybeAutoReply($pdo, $client['client_id'], $client['client_name'], 'li_comment', $fromId, $fromName, $externalId, null); }
    catch (\Throwable $e) { error_log('zernio-webhook reply-bot EXCEPTION: ' . $e->getMessage()); }
} catch (\Throwable $e) {
    error_log('zernio-webhook EXCEPTION: ' . $e->getMessage());
}
