<?php
/**
 * trello-webhook-register.php — creates (or removes) the Trello webhook
 * for a connected board, called once from the "Connect Trello" UI when
 * the admin saves an integration whose sync_direction includes pulling
 * FROM Trello. Builds the callback URL from the request itself (same
 * host this endpoint is reached on) — no hardcoded domain to keep in
 * sync with config.php.
 */

require_once __DIR__ . '/trello-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$apiKey = trim($data['api_key'] ?? '');
$token = trim($data['token'] ?? '');
$boardId = trim($data['board_id'] ?? '');
$existingWebhookId = trim($data['existing_webhook_id'] ?? '');

if ($existingWebhookId) {
    trello_delete_webhook($apiKey, $token, $existingWebhookId);
    if (!$boardId) { echo json_encode(["ok" => true, "removed" => true]); exit; }
}

if (!$apiKey || !$token || !$boardId) {
    http_response_code(400);
    echo json_encode(["error" => "api_key, token, and board_id are all required."]);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$callbackUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/trello-webhook.php';

$result = trello_create_webhook($apiKey, $token, $callbackUrl, $boardId);
if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(["error" => $result['error']]);
    exit;
}

echo json_encode(["ok" => true, "webhook_id" => $result['webhook_id'], "callback_url" => $callbackUrl]);
