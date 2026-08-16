<?php
/**
 * trello-lists.php — used only during the "Connect Trello" setup flow in
 * the client's Integrations tab: given an API key/token and a board
 * URL/ID, resolves the board and returns its lists so the admin can map
 * each SocialFlow stage to a Trello list. No DB access — nothing is saved
 * here, the wizard saves everything itself once the admin hits Save.
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
$token  = trim($data['token'] ?? '');
$board  = trim($data['board'] ?? '');

if (!$apiKey || !$token || !$board) {
    http_response_code(400);
    echo json_encode(["error" => "api_key, token, and board are all required."]);
    exit;
}

$resolved = trello_resolve_board($apiKey, $token, $board);
if (!$resolved['ok']) {
    http_response_code(400);
    echo json_encode(["error" => $resolved['error']]);
    exit;
}

$listsResult = trello_get_lists($apiKey, $token, $resolved['id']);
if (!$listsResult['ok']) {
    http_response_code(400);
    echo json_encode(["error" => $listsResult['error']]);
    exit;
}

echo json_encode(["ok" => true, "board_id" => $resolved['id'], "board_name" => $resolved['name'], "lists" => $listsResult['lists']]);
