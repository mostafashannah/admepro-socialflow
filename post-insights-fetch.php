<?php
/**
 * post-insights-fetch.php — on-demand refresh of a single post's real
 * engagement (likes/comments/shares/reach), for the "Refresh Now" button
 * on the Insights tab of a published post's detail view. Mirrors the
 * per-platform fetch logic in post-insights-cron.php (which runs this for
 * every eligible post once a day) but scoped to one post_id, called
 * synchronously from the frontend so the button gets an immediate result.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';
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

$stmt = $pdo->prepare("SELECT id, client_id, platform, stage, external_post_id FROM posts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { http_response_code(404); echo json_encode(["error"=>"Post not found"]); exit; }
if ($post['stage'] !== 'published' || empty($post['external_post_id'])) {
    http_response_code(400); echo json_encode(["error"=>"Post has no published external ID yet"]); exit;
}

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}

$v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';

$integStmt = $pdo->prepare(
    "SELECT id, credentials FROM integrations WHERE status = 'active' AND app_key = :platform
       AND (client_id = :client_id OR client_id IS NULL OR client_id = '')
     ORDER BY (client_id = :client_id2) DESC LIMIT 1"
);
$integStmt->execute([':platform' => $post['platform'], ':client_id' => $post['client_id'], ':client_id2' => $post['client_id']]);
$integ = $integStmt->fetch(PDO::FETCH_ASSOC);
if (!$integ) { http_response_code(400); echo json_encode(["error"=>"No active integration for this platform"]); exit; }

$creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
$access_token = trim($creds['access_token'] ?? '');
if ($post['platform'] === 'tiktok') {
    $access_token = tiktok_get_fresh_token($pdo, $integ['id'], $creds) ?? $access_token;
}
if (!$access_token) { http_response_code(400); echo json_encode(["error"=>"Integration has no access token"]); exit; }

$postId = $post['external_post_id'];
$likes = $comments = $shares = $reach = null;

if ($post['platform'] === 'tiktok') {
    if (tiktok_looks_like_publish_id($postId)) {
        $resolved = tiktok_resolve_video_id($access_token, $postId);
        if ($resolved) {
            $postId = $resolved;
            $pdo->prepare("UPDATE posts SET external_post_id = :ext WHERE id = :id")->execute([':ext' => $postId, ':id' => $post_id]);
        } else {
            http_response_code(502);
            echo json_encode(["error" => "TikTok hasn't made this video's public ID available yet — it may still be processing, or (if posted as Private) it may never be publicly queryable. Try again in a few minutes."]);
            exit;
        }
    }
    [$code, $resp] = tiktok_video_insights($access_token, $postId);
    if ($code === 200) {
        $vd = $resp['data']['videos'][0] ?? null;
        if ($vd) {
            $likes    = $vd['like_count'] ?? null;
            $comments = $vd['comment_count'] ?? null;
            $shares   = $vd['share_count'] ?? null;
            $reach    = $vd['view_count'] ?? null;
        }
    }
} elseif ($post['platform'] === 'facebook') {
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$postId}", [
        "fields" => "likes.summary(true),comments.summary(true),shares",
        "access_token" => $access_token,
    ]);
    if ($code === 200) {
        $likes    = $resp['likes']['summary']['total_count'] ?? null;
        $comments = $resp['comments']['summary']['total_count'] ?? null;
        $shares   = $resp['shares']['count'] ?? 0;
    }
    [$rcode, $rresp] = graph_get("https://graph.facebook.com/{$v}/{$postId}/insights", [
        "metric" => "post_impressions_unique", "access_token" => $access_token,
    ]);
    if ($rcode === 200) {
        $reach = $rresp['data'][0]['values'][0]['value'] ?? null;
    }
} else {
    $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';
    [$code, $resp] = graph_get("https://{$ig_host}/{$v}/{$postId}", [
        "fields" => "like_count,comments_count",
        "access_token" => $access_token,
    ]);
    if ($code === 200) {
        $likes    = $resp['like_count'] ?? null;
        $comments = $resp['comments_count'] ?? null;
    }
    [$rcode, $rresp] = graph_get("https://{$ig_host}/{$v}/{$postId}/insights", [
        "metric" => "reach", "access_token" => $access_token,
    ]);
    if ($rcode === 200) {
        $reach = $rresp['data'][0]['values'][0]['value'] ?? null;
    }
}

if ($likes === null && $comments === null && $reach === null) {
    http_response_code(502); echo json_encode(["error"=>"Platform returned no data — the post may be too new, deleted, or the token may have expired"]); exit;
}

$fetchedAt = date('c');
$update = $pdo->prepare(
    "UPDATE posts SET insight_likes = :likes, insight_comments = :comments, insight_shares = :shares,
       insight_reach = :reach, insight_fetched_at = NOW() WHERE id = :id"
);
$update->execute([':likes'=>$likes, ':comments'=>$comments, ':shares'=>$shares, ':reach'=>$reach, ':id'=>$post_id]);

echo json_encode(["likes"=>$likes, "comments"=>$comments, "shares"=>$shares, "reach"=>$reach, "fetched_at"=>$fetchedAt]);
