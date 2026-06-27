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
$externalId   = trim($data['external_id']   ?? ''); // comment_id, required for fb_comment/ig_comment

if (!$channel || !$accessToken || !$message || (!$recipientId && !$externalId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: channel, access_token, message, and recipient_id (DMs) or external_id (comments)']);
    exit;
}

if (!in_array($channel, ['messenger', 'instagram', 'fb_comment', 'ig_comment'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported channel. Supported: messenger, instagram, fb_comment, ig_comment']);
    exit;
}

$graph_version = 'v19.0';
$isComment = $channel === 'fb_comment' || $channel === 'ig_comment';

if ($isComment) {
    if (!$externalId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing external_id (comment id) for comment reply']);
        exit;
    }
    $graph_host = ($channel === 'ig_comment' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    // Facebook Page comments: POST /{comment-id}/comments. Instagram: dedicated /replies endpoint.
    // v23.0: comment replies map to pages_manage_engagement; legacy v19.0 demanded the
    // deprecated pages_read_user_content that Meta no longer issues.
    $path = $channel === 'ig_comment' ? 'replies' : 'comments';
    $endpoint = "https://{$graph_host}/v23.0/{$externalId}/{$path}";
    $post_data = ['message' => $message, 'access_token' => $accessToken];
} else {
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: page_id']);
        exit;
    }
    // Instagram accounts connected via "Instagram API with Instagram Login" issue tokens
    // prefixed "IGAA" that are scoped to graph.instagram.com — graph.facebook.com can't
    // even parse them ("Cannot parse access token"). Messenger/Page tokens still use
    // graph.facebook.com as before.
    $graph_host = ($channel === 'instagram' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $endpoint = "https://{$graph_host}/{$graph_version}/{$pageId}/messages";
    $post_data = [
        'recipient'    => json_encode(['id' => $recipientId]),
        'message'      => json_encode(['text' => $message]),
        'access_token' => $accessToken,
    ];
}

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
