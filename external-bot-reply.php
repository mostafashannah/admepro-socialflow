<?php
// ================================================================
// SocialFlow — external reply-bot relay: send endpoint.
//
// Lets a client's own reply-bot system send a message to their customer
// through SocialFlow's connected Meta credentials, without ever being
// handed those credentials directly. Pairs with forwardToExternalBot() in
// reply-bot-lib.php, which POSTs each inbound message to the client's own
// webhook URL — their system decides what to reply, then calls THIS
// endpoint to actually send it.
//
// Auth: each client has their own external_api_key (reply_bot_settings,
// generated in Settings → Reply Bot → "Client has their own reply bot").
// This is NOT the same as SocialFlow's global API_KEY — a leaked per-client
// key only lets someone send messages as that one client's connected
// account, not access anything else in the system.
//
// POST body (JSON):
//   { "client_id": "...", "channel": "instagram"|"messenger"|"fb_comment"|"ig_comment"|"whatsapp",
//     "recipient_id": "customer PSID/IGSID/phone" (DMs), "external_id": "comment_id" (comments),
//     "message": "the reply text" }
// Auth header: X-Api-Key: <external_api_key>  (or Authorization: Bearer <external_api_key>)
// ================================================================
require_once 'config.php';
require_once 'reply-bot-lib.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!$providedKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing API key — send it as X-Api-Key or Authorization: Bearer']);
    exit;
}

$data        = json_decode(file_get_contents('php://input'), true);
$clientId    = trim($data['client_id']    ?? '');
$channel     = strtolower(trim($data['channel'] ?? ''));
$recipientId = trim($data['recipient_id'] ?? '');
$externalId  = trim($data['external_id']  ?? '');
$message     = trim($data['message']      ?? '');

if (!$clientId || !$channel || !$message || (!$recipientId && !$externalId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: client_id, channel, message, and recipient_id (DMs) or external_id (comments)']);
    exit;
}
if (!in_array($channel, ['messenger', 'instagram', 'fb_comment', 'ig_comment', 'whatsapp'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported channel. Supported: messenger, instagram, fb_comment, ig_comment, whatsapp']);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$settings = getReplyBotSettings($pdo, $clientId);
if (!$settings || empty($settings['external_bot']) || empty($settings['external_api_key'])) {
    http_response_code(403);
    echo json_encode(['error' => 'External bot relay is not enabled for this client']);
    exit;
}
if (!hash_equals((string)$settings['external_api_key'], (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// Same app_key mapping findIntegrationCreds() already uses elsewhere in this
// file — comments (fb_comment/ig_comment) send via the matching platform's
// own integration (facebook/instagram), not a separate app_key of their own.
$appKey = $channel === 'whatsapp' ? 'whatsapp' : (str_starts_with($channel, 'ig') || $channel === 'instagram' ? 'instagram' : 'facebook');
$creds = findIntegrationCreds($pdo, $clientId, $appKey);
if (!$creds) {
    http_response_code(409);
    echo json_encode(['error' => "No active {$appKey} integration connected for this client"]);
    exit;
}

$isComment  = $channel === 'fb_comment' || $channel === 'ig_comment';
$isWhatsApp = $channel === 'whatsapp';
$accessToken = $creds['access_token'];
$graph_version = 'v19.0';

if ($isWhatsApp) {
    if (empty($creds['phone_id'])) { http_response_code(409); echo json_encode(['error' => 'WhatsApp integration missing phone_id']); exit; }
    $to = '+' . preg_replace('/\D/', '', $recipientId);
    $endpoint = "https://graph.facebook.com/{$graph_version}/{$creds['phone_id']}/messages";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$accessToken}"],
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $message]]),
    ]);
} elseif ($isComment) {
    if (!$externalId) { http_response_code(400); echo json_encode(['error' => 'Missing external_id (comment id) for comment reply']); exit; }
    $graph_host = ($channel === 'ig_comment' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $path = $channel === 'ig_comment' ? 'replies' : 'comments';
    $endpoint = "https://{$graph_host}/v23.0/{$externalId}/{$path}";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => http_build_query(['message' => $message, 'access_token' => $accessToken]),
    ]);
} else {
    if (empty($creds['page_id'])) { http_response_code(409); echo json_encode(['error' => 'Integration missing page_id']); exit; }
    $graph_host = ($channel === 'instagram' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $endpoint = "https://{$graph_host}/{$graph_version}/{$creds['page_id']}/messages";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'recipient' => json_encode(['id' => $recipientId]),
            'message' => json_encode(['text' => $message]),
            'access_token' => $accessToken,
        ]),
    ]);
}

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) { http_response_code(502); echo json_encode(['error' => "cURL error: {$curl_err}"]); exit; }

// Record the outbound reply in the same Inbox thread the customer's message
// came in on, so the team sees the full conversation even though the reply
// itself was sent by the client's own system, not a human or our own bot.
if ($http_code >= 200 && $http_code < 300) {
    $clientName = $settings['client_name'] ?? '';
    $stmt = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status, external_id) VALUES (:cid, :cname, :ch, :custid, NULL, 'out', :txt, 'external_bot', 'replied', :eid)");
    $stmt->execute([':cid' => $clientId, ':cname' => $clientName, ':ch' => $channel, ':custid' => $recipientId ?: $externalId, ':txt' => $message, ':eid' => $externalId ?: null]);
}

http_response_code($http_code);
echo $response;
