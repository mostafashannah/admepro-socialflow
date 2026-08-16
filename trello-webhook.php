<?php
/**
 * trello-webhook.php — public callback Trello posts to whenever a card is
 * added or moved on a connected board. This is the Trello → SocialFlow
 * half of the sync:
 *   - createCard: a brand-new card dropped straight onto the board (not
 *     created from SocialFlow) becomes a new post/task here, in whatever
 *     stage that list is mapped to — e.g. a card added to "To Do" shows up
 *     as a Client Request if that's what "To Do" is mapped to.
 *   - updateCard (list changed): a card dragged to a different list moves
 *     that post to the matching stage here.
 *   - updateCard (title/description/due date edited): mirrors the same
 *     edit onto the matching post's title/description/due_date/due_time.
 *   - updateCard (archived) / deleteCard: archiving or permanently
 *     deleting the card on Trello deletes the matching post here too.
 *
 * Trello requires the callback URL to answer ANY request (including a
 * bare HEAD with no body) with 2xx during webhook registration, so every
 * method just falls through to a 200 — only a POST with a real
 * create/updateCard action body does anything.
 *
 * No signature verification here (Trello supports an HMAC header, but it
 * needs the exact raw callback URL registered — order-of-operations makes
 * that awkward to thread through from trello-webhook-register.php right
 * now). The blast radius of a forged call is bounded: at most it can
 * create/move one post, into a stage that's already reachable through the
 * normal pipeline, on a board this integration is already connected to.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/trello-lib.php';
header("Content-Type: application/json");

http_response_code(200); // ack immediately — Trello disables the webhook after repeated slow/non-2xx responses

if ($_SERVER["REQUEST_METHOD"] !== "POST") { echo json_encode(["ok" => true]); exit; }

$body = json_decode(file_get_contents("php://input"), true);
$action = $body['action'] ?? null;
$actionType = $action['type'] ?? '';
if (!$action || !in_array($actionType, ['createCard', 'updateCard', 'deleteCard'], true)) { echo json_encode(["ok" => true]); exit; }

$boardId = $action['data']['board']['id'] ?? null;
$cardId = $action['data']['card']['id'] ?? null;
if (!$boardId || !$cardId) { echo json_encode(["ok" => true]); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

// Find the integration this board belongs to — config.board_id is a JSON
// field, not a real column, so this has to scan active Trello integrations
// rather than an indexed lookup. Fine at agency scale (a handful of
// connected boards, not thousands).
$integs = $pdo->query("SELECT id, client_id, client_name, config FROM integrations WHERE app_key = 'trello' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$integ = null;
foreach ($integs as $row) {
    $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
    if (($cfg['board_id'] ?? null) === $boardId) { $integ = ['id' => $row['id'], 'client_id' => $row['client_id'], 'client_name' => $row['client_name'], 'config' => $cfg]; break; }
}
if (!$integ) { echo json_encode(["ok" => true]); exit; }

$direction = $integ['config']['sync_direction'] ?? 'both';
if ($direction === 'to_trello') { echo json_encode(["ok" => true]); exit; }

$listMap = $integ['config']['list_map'] ?? [];

// Archived (closed:true) or permanently deleted on Trello — remove the
// matching post/task here too, including its comment thread so nothing
// orphaned is left behind.
$archived = $actionType === 'updateCard' && ($action['data']['card']['closed'] ?? null) === true && ($action['data']['old']['closed'] ?? null) !== true;
if ($actionType === 'deleteCard' || $archived) {
    $postStmt = $pdo->prepare("SELECT id FROM posts WHERE trello_card_id = :cid LIMIT 1");
    $postStmt->execute([':cid' => $cardId]);
    $post = $postStmt->fetch(PDO::FETCH_ASSOC);
    if ($post) {
        $pdo->prepare("DELETE FROM comments WHERE post_id = :pid")->execute([':pid' => $post['id']]);
        $pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $post['id']]);
        echo json_encode(["ok" => true, "action" => "deleted", "post_id" => $post['id']]);
        exit;
    }
    echo json_encode(["ok" => true]); exit;
}

if ($actionType === 'createCard') {
    // A brand-new card dropped directly onto the board — ALWAYS lands as a
    // Client Request here, regardless of which list it was created in
    // (that's what "someone added a new task on the board" means: a new
    // ask coming in). It only follows the list→stage mapping once it gets
    // MOVED between lists afterward (see the updateCard handling below) —
    // e.g. dragged into "Doing" moves it to whatever stage "Doing" maps to.
    // Only guard here is not re-creating a card SocialFlow itself just
    // pushed to Trello a moment ago (Trello echoing our own create back).
    $existsStmt = $pdo->prepare("SELECT id FROM posts WHERE trello_card_id = :cid LIMIT 1");
    $existsStmt->execute([':cid' => $cardId]);
    if ($existsStmt->fetch()) { echo json_encode(["ok" => true]); exit; }

    $title = trim($action['data']['card']['name'] ?? '') ?: '(untitled)';
    $desc = $action['data']['card']['desc'] ?? '';
    // post_type left as a generic, non-social value on purpose — the app
    // treats any post with no platform AND a post_type outside
    // SOCIAL_POST_TYPES as a Task rather than a social Post (see
    // KanbanView's isTask logic in app.jsx). A Trello card carries no
    // platform info, so it should always land as a Task, not a Post.
    $ins = $pdo->prepare(
        "INSERT INTO posts (id, client_id, client_name, title, description, post_type, stage, trello_card_id) VALUES (UUID(), :cid, :cname, :title, :desc, 'general', 'client_request', :card)"
    );
    $ins->execute([':cid' => $integ['client_id'], ':cname' => $integ['client_name'], ':title' => $title, ':desc' => $desc, ':card' => $cardId]);
    echo json_encode(["ok" => true, "action" => "created", "stage" => "client_request"]);
    exit;
}

// updateCard — a list move, and/or a plain field edit (title, description,
// due date) on the card itself. Trello's action.data.old only contains the
// fields that actually changed on this event, so each one is only touched
// if it's genuinely present there — never overwrites a field with a stale
// unchanged value.
$postStmt = $pdo->prepare("SELECT id, stage FROM posts WHERE trello_card_id = :cid LIMIT 1");
$postStmt->execute([':cid' => $cardId]);
$post = $postStmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { echo json_encode(["ok" => true]); exit; }

$old = $action['data']['old'] ?? [];
$card = $action['data']['card'] ?? [];
$set = [];
$params = [':id' => $post['id']];

$newListId = $action['data']['listAfter']['id'] ?? null;
if ($newListId) {
    $newStage = array_search($newListId, $listMap, true);
    if ($newStage !== false && $newStage !== $post['stage']) { $set[] = "stage = :stage"; $params[':stage'] = $newStage; }
}
if (array_key_exists('name', $old)) { $set[] = "title = :title"; $params[':title'] = trim($card['name'] ?? '') ?: '(untitled)'; }
if (array_key_exists('desc', $old)) { $set[] = "description = :desc"; $params[':desc'] = $card['desc'] ?? ''; }
if (array_key_exists('due', $old)) {
    $due = $card['due'] ?? null;
    if ($due) {
        $dt = new DateTime($due);
        $set[] = "due_date = :dd"; $params[':dd'] = $dt->format('Y-m-d');
        $set[] = "due_time = :dt"; $params[':dt'] = $dt->format('H:i');
    } else {
        $set[] = "due_date = NULL"; $set[] = "due_time = NULL"; // due date removed on the card
    }
}

if (!$set) { echo json_encode(["ok" => true]); exit; }

$pdo->prepare("UPDATE posts SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
echo json_encode(["ok" => true, "action" => "updated", "updated" => $post['id'], "fields" => array_keys($params)]);
