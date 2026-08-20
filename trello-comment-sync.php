<?php
/**
 * trello-comment-sync.php — pushes a SocialFlow comment (and its
 * attachment, if any) onto the matching Trello card, called from the
 * frontend right after a comment is added to a post. Same no-op-is-fine
 * shape as trello-sync.php: nothing to do isn't an error, it just means
 * this client has no active Trello integration, no card linked yet, or
 * the integration's sync direction doesn't push TO Trello.
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
$text = trim($data['text'] ?? '');
$authorName = trim($data['author_name'] ?? '');
$fileUrl = trim($data['file_url'] ?? '');
$fileName = trim($data['file_name'] ?? '');
if (!$postId || ($text === '' && $fileUrl === '')) { echo json_encode(["ok" => true, "skipped" => "Nothing to sync"]); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$stmt = $pdo->prepare("SELECT id, client_id, trello_card_id FROM posts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post || !$post['client_id'] || !$post['trello_card_id']) { echo json_encode(["ok" => true, "skipped" => "Post has no linked Trello card"]); exit; }

$integStmt = $pdo->prepare("SELECT credentials, config FROM integrations WHERE app_key = 'trello' AND client_id = :cid AND status = 'active' LIMIT 1");
$integStmt->execute([':cid' => $post['client_id']]);
$integ = $integStmt->fetch(PDO::FETCH_ASSOC);
if (!$integ) { echo json_encode(["ok" => true, "skipped" => "No active Trello integration for this client"]); exit; }

$creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
$config = json_decode($integ['config'] ?? '{}', true) ?: [];
$apiKey = $creds['api_key'] ?? '';
$token = $creds['token'] ?? '';
$direction = $config['sync_direction'] ?? 'both';
if (!$apiKey || !$token) { echo json_encode(["ok" => true, "skipped" => "Trello integration missing credentials"]); exit; }
// "Trello -> SocialFlow only" blocks comment sync too, UNLESS the
// integration has explicitly opted into pushing comments out on top of
// that (a real combination someone wants: keep incoming card-move sync,
// also get comments flowing out) via the "Also push comments to Trello"
// checkbox.
if ($direction === 'from_trello' && empty($config['push_comments_out'])) { echo json_encode(["ok" => true, "skipped" => "This integration is set to Trello → SocialFlow only"]); exit; }

// An attachment rides along as a plain link inside the comment text
// itself (not a separate native Trello card attachment) — a client
// scanning the comment thread sees "here's the file" right there instead
// of having to notice a new item appeared in the card's Attachments
// section separately.
$results = [];
if ($text !== '' || $fileUrl !== '') {
    $body = ($authorName ? "{$authorName}: " : '') . $text;
    if ($fileUrl !== '') {
        $previewUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'socialflow.admepro.com') . "/file-preview.php?u=" . urlencode($fileUrl) . "&n=" . urlencode($fileName ?: 'Attachment');
        $body = trim($body . "\n" . ($fileName ?: 'Attachment') . ": {$previewUrl}");
    }
    $results['comment'] = trello_add_comment($apiKey, $token, $post['trello_card_id'], $body);
}

echo json_encode(["ok" => true, "results" => $results]);
