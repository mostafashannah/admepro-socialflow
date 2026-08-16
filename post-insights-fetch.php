<?php
/**
 * post-insights-fetch.php — on-demand refresh of a single post's real
 * engagement (likes/comments/shares/reach), for the "Refresh Now" button
 * on the Insights tab of a published post's detail view.
 *
 * A post can be published to MORE than one platform at once (platforms
 * JSON array — the legacy `platform` column only ever holds ONE of them).
 * This used to only ever fetch insights for that single legacy column, so
 * a post published to Instagram + Facebook only showed Facebook engagement
 * if `platform` happened to equal 'facebook' — which for most
 * multi-platform posts it didn't. Now delegates to
 * refresh_post_insights_all_platforms() (post-insights-lib.php, shared
 * with post-insights-cron.php) which fetches every platform the post
 * actually went out to.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';
require_once __DIR__ . '/post-insights-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$data    = json_decode(file_get_contents("php://input"), true);
$post_id = trim($data["post_id"] ?? "");
if (!$post_id) { http_response_code(400); echo json_encode(["error"=>"Missing post_id"]); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$stmt = $pdo->prepare("SELECT id, client_id, platform, platforms, stage, post_type, external_post_id, platform_post_ids, insights_by_platform FROM posts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { http_response_code(404); echo json_encode(["error"=>"Post not found"]); exit; }
if ($post['stage'] !== 'published') {
    http_response_code(400); echo json_encode(["error"=>"This post hasn't been published yet"]); exit;
}

$result = refresh_post_insights_all_platforms($pdo, $post);

if (!$result['ok']) {
    http_response_code(502);
    $errors = $result['errors'] ?? [];
    echo json_encode(["error" => $errors ? ("Platform error: " . implode('; ', array_map(fn($p,$e)=>"{$p}: {$e}", array_keys($errors), $errors))) : "Platform returned no data — the post may be too new, deleted, or the token may have expired"]);
    exit;
}

echo json_encode([
    "likes"=>$result['likes'], "comments"=>$result['comments'], "shares"=>$result['shares'], "reach"=>$result['reach'],
    "by_platform"=>$result['by_platform'], "errors"=>$result['errors'], "fetched_at"=>date('c'),
]);
