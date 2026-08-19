<?php
/**
 * trello-sync.php — pushes a post/task's current stage to its client's
 * connected Trello board, called from the frontend right after a post is
 * created (as a client request) or its stage changes (e.g. given a due
 * date and moved to Brief, or moved to Client Approval).
 *
 * A no-op (not an error) whenever: the client has no active Trello
 * integration, the integration's sync_direction doesn't include pushing TO
 * Trello, or the current stage isn't mapped to a Trello list — none of
 * these are failures, they just mean there's nothing to do.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/trello-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$postId = trim($data['post_id'] ?? '');
if (!$postId) { http_response_code(400); echo json_encode(["error" => "Missing post_id"]); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$stmt = $pdo->prepare("SELECT id, client_id, title, description, caption, stage, trello_card_id FROM posts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { http_response_code(404); echo json_encode(["error" => "Post not found"]); exit; }
if (!$post['client_id']) { echo json_encode(["ok" => true, "skipped" => "Post has no client linked"]); exit; }

$integStmt = $pdo->prepare("SELECT id, credentials, config FROM integrations WHERE app_key = 'trello' AND client_id = :cid AND status = 'active' LIMIT 1");
$integStmt->execute([':cid' => $post['client_id']]);
$integ = $integStmt->fetch(PDO::FETCH_ASSOC);
if (!$integ) { echo json_encode(["ok" => true, "skipped" => "No active Trello integration for this client"]); exit; }

$creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
$config = json_decode($integ['config'] ?? '{}', true) ?: [];
$apiKey = $creds['api_key'] ?? '';
$token = $creds['token'] ?? '';
$direction = $config['sync_direction'] ?? 'both';
$listMap = $config['list_map'] ?? [];

if (!$apiKey || !$token) { echo json_encode(["ok" => true, "skipped" => "Trello integration missing credentials"]); exit; }
if ($direction === 'from_trello' || $direction === 'to_trello_comments_only') { echo json_encode(["ok" => true, "skipped" => "This integration doesn't push card creation/stage moves to Trello"]); exit; }

$targetListId = $listMap[$post['stage']] ?? null;
if (!$targetListId) { echo json_encode(["ok" => true, "skipped" => "Stage \"{$post['stage']}\" isn't mapped to a Trello list"]); exit; }

if (!$post['trello_card_id']) {
    $desc = trim(($post['description'] ?? '') . ($post['caption'] ? "\n\n" . $post['caption'] : ''));
    $result = trello_create_card($apiKey, $token, $targetListId, $post['title'] ?: '(untitled)', $desc);
    if (!$result['ok']) { echo json_encode(["ok" => false, "error" => $result['error']]); exit; }
    $pdo->prepare("UPDATE posts SET trello_card_id = :cid WHERE id = :id")->execute([':cid' => $result['card_id'], ':id' => $post['id']]);
    echo json_encode(["ok" => true, "action" => "created", "card_id" => $result['card_id']]);
} else {
    $result = trello_move_card($apiKey, $token, $post['trello_card_id'], $targetListId);
    if (!$result['ok']) { echo json_encode(["ok" => false, "error" => $result['error']]); exit; }
    echo json_encode(["ok" => true, "action" => "moved"]);
}
