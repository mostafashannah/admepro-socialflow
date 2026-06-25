<?php
// ================================================================
// Sends a manual reply to a customer on Messenger or Instagram,
// using the client's own Page access token. Called from the
// per-client Inbox tab in app.jsx.
// ================================================================
require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$data         = json_decode(file_get_contents('php://input'), true);
$channel      = strtolower(trim($data['channel']      ?? ''));
$recipientId  = trim($data['recipient_id']  ?? '');
$pageId       = trim($data['page_id']       ?? '');
$accessToken  = trim($data['access_token']  ?? '');
$message      = trim($data['message']       ?? '');

if (!$channel || !$recipientId || !$pageId || !$accessToken || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: channel, recipient_id, page_id, access_token, message']);
    exit;
}

if (!in_array($channel, ['messenger', 'instagram'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported channel. Supported: messenger, instagram']);
    exit;
}

$graph_version = 'v19.0';
$endpoint = "https://graph.facebook.com/{$graph_version}/{$pageId}/messages";
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
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    http_response_code(502);
    echo json_encode(['error' => "cURL error: {$curl_err}"]);
    exit;
}

http_response_code($http_code);
echo $response;
