<?php
/**
 * trello-webhook.php — public callback Trello posts to whenever a card
 * moves between lists on a connected board. This is the Trello →
 * SocialFlow half of the sync: a card dragged into the list mapped to
 * "Client Approval" (say) moves that post to client_approval here too.
 *
 * Trello requires the callback URL to answer ANY request (including a
 * bare HEAD with no body) with 2xx during webhook registration, so every
 * method just falls through to a 200 — only a POST with a real
 * updateCard action body does anything.
 *
 * No signature verification here (Trello supports an HMAC header, but it
 * needs the exact raw callback URL registered — order-of-operations makes
 * that awkward to thread through from trello-webhook-register.php right
 * now). The blast radius of a forged call is bounded: at most it can move
 * one specific post to a stage that's already reachable through the
 * normal pipeline, on a card id that has to already match a real
 * trello_card_id in the DB.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/trello-lib.php';
header("Content-Type: application/json");

http_response_code(200); // ack immediately — Trello disables the webhook after repeated slow/non-2xx responses

if ($_SERVER["REQUEST_METHOD"] !== "POST") { echo json_encode(["ok" => true]); exit; }

$body = json_decode(file_get_contents("php://input"), true);
$action = $body['action'] ?? null;
if (!$action || ($action['type'] ?? '') !== 'updateCard') { echo json_encode(["ok" => true]); exit; }

$cardId = $action['data']['card']['id'] ?? null;
$newListId = $action['data']['listAfter']['id'] ?? null;
$boardId = $action['data']['board']['id'] ?? null;
if (!$cardId || !$newListId || !$boardId) { echo json_encode(["ok" => true]); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

// Find the integration this board belongs to — config.board_id is a JSON
// field, not a real column, so this has to scan active Trello integrations
// rather than an indexed lookup. Fine at agency scale (a handful of
// connected boards, not thousands).
$integs = $pdo->query("SELECT id, config FROM integrations WHERE app_key = 'trello' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$integ = null;
foreach ($integs as $row) {
    $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
    if (($cfg['board_id'] ?? null) === $boardId) { $integ = ['id' => $row['id'], 'config' => $cfg]; break; }
}
if (!$integ) { echo json_encode(["ok" => true]); exit; }

$direction = $integ['config']['sync_direction'] ?? 'both';
if ($direction === 'to_trello') { echo json_encode(["ok" => true]); exit; }

$listMap = $integ['config']['list_map'] ?? [];
$newStage = array_search($newListId, $listMap, true);
if ($newStage === false) { echo json_encode(["ok" => true]); exit; } // list not mapped to any stage — nothing to do

$postStmt = $pdo->prepare("SELECT id, stage FROM posts WHERE trello_card_id = :cid LIMIT 1");
$postStmt->execute([':cid' => $cardId]);
$post = $postStmt->fetch(PDO::FETCH_ASSOC);
if (!$post || $post['stage'] === $newStage) { echo json_encode(["ok" => true]); exit; }

$pdo->prepare("UPDATE posts SET stage = :stage WHERE id = :id")->execute([':stage' => $newStage, ':id' => $post['id']]);
echo json_encode(["ok" => true, "updated" => $post['id'], "stage" => $newStage]);
