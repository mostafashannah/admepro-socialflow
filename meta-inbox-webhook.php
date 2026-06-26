<?php
// ================================================================
// Receives Messenger + Instagram DM webhook events from Meta and
// stores them per-client in customer_messages, by matching the
// page_id in the event against integrations.credentials.page_id.
// ================================================================
require_once 'config.php';

// ── Webhook verification (Meta calls this once when you subscribe) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $token === META_WEBHOOK_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input');

// ── Verify request signature (X-Hub-Signature-256) ──
// Facebook Pages and Instagram (API with Instagram login) are separate Meta
// apps with separate secrets — a webhook event can be signed with either one
// depending on which app's dashboard the subscription was configured under.
// Accept the request if it matches either app's secret.
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!$sig) {
    http_response_code(403);
    exit;
}
$secrets = array_filter([
    defined('META_APP_SECRET') ? META_APP_SECRET : null,
    defined('INSTAGRAM_APP_SECRET') ? INSTAGRAM_APP_SECRET : null,
]);
$signatureValid = false;
foreach ($secrets as $secret) {
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if (hash_equals($expected, $sig)) { $signatureValid = true; break; }
}
if (!$signatureValid) {
    http_response_code(403);
    exit;
}

$body = json_decode($raw, true);
http_response_code(200); // ack immediately, Meta requires a fast 200
echo 'EVENT_RECEIVED';

if (!$body || empty($body['entry'])) { exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function findClientByPageId(PDO $pdo, string $pageId) {
    $stmt = $pdo->prepare("SELECT id, name, credentials FROM integrations WHERE status='active' AND app_key IN ('facebook','instagram') AND client_id IS NOT NULL");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
        if (($creds['page_id'] ?? '') === $pageId) {
            // need client_id/client_name from the integrations row itself
            $stmt2 = $pdo->prepare("SELECT client_id, client_name FROM integrations WHERE id = :id");
            $stmt2->execute([':id' => $row['id']]);
            return $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }
    return null;
}

function storeMessage(PDO $pdo, $clientId, $clientName, $channel, $customerId, $text) {
    $stmt = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status) VALUES (:cid, :cname, :ch, :custid, NULL, 'in', :txt, 'customer', 'needs_human')");
    $stmt->execute([':cid'=>$clientId, ':cname'=>$clientName, ':ch'=>$channel, ':custid'=>$customerId, ':txt'=>$text]);
}

foreach ($body['entry'] as $entry) {
    $pageId = $entry['id'] ?? '';
    if (!$pageId) continue;
    $client = findClientByPageId($pdo, $pageId);
    if (!$client) continue; // page not connected to any client — ignore

    // Messenger
    foreach (($entry['messaging'] ?? []) as $m) {
        $text = $m['message']['text'] ?? null;
        $senderId = $m['sender']['id'] ?? null;
        if ($text && $senderId) {
            storeMessage($pdo, $client['client_id'], $client['client_name'], 'messenger', $senderId, $text);
        }
    }

    // Instagram DMs arrive under "changes" with field "messages" on some setups,
    // or under "messaging" like Messenger on others — handle both shapes.
    foreach (($entry['changes'] ?? []) as $c) {
        if (($c['field'] ?? '') === 'messages') {
            $text = $c['value']['message']['text'] ?? null;
            $senderId = $c['value']['sender']['id'] ?? null;
            if ($text && $senderId) {
                storeMessage($pdo, $client['client_id'], $client['client_name'], 'instagram', $senderId, $text);
            }
        }
    }
}
