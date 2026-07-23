<?php
/**
 * post-insights-cron.php — refreshes per-post engagement (likes/comments/
 * shares/reach) for recently published Facebook/Instagram posts, so a
 * "Best Performing Posts" ranking can be shown to staff and clients.
 *
 * Setup on Hostinger (same pattern as meta-insights-cron.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/post-insights-cron.php
 *   Schedule: once daily, e.g. 0 2 * * *
 *
 * Only looks at posts published in the last 30 days (older posts' engagement
 * has usually settled, and this keeps the per-run API call count small).
 */

// CLI-only — this script performs real writes (emails, DB records) and has
// no authentication of its own, so it must never be reachable over plain
// HTTP (this file sits in the public web root alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

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
$since = date('Y-m-d H:i:s', strtotime('-30 days'));

$posts = $pdo->prepare(
    "SELECT id, client_id, platform, external_post_id FROM posts
     WHERE stage = 'published' AND external_post_id IS NOT NULL AND external_post_id <> ''
       AND (published_at IS NULL OR published_at >= :since)"
);
$posts->execute([':since' => $since]);
$rows = $posts->fetchAll(PDO::FETCH_ASSOC);

$integStmt = $pdo->prepare(
    "SELECT id, credentials FROM integrations WHERE status = 'active' AND app_key = :platform
       AND (client_id = :client_id OR client_id IS NULL OR client_id = '')
     ORDER BY (client_id = :client_id2) DESC LIMIT 1"
);

$update = $pdo->prepare(
    "UPDATE posts SET insight_likes = :likes, insight_comments = :comments, insight_shares = :shares,
       insight_reach = :reach, insight_fetched_at = NOW() WHERE id = :id"
);

$updated = 0;
foreach ($rows as $post) {
    $integStmt->execute([':platform' => $post['platform'], ':client_id' => $post['client_id'], ':client_id2' => $post['client_id']]);
    $integ = $integStmt->fetch(PDO::FETCH_ASSOC);
    if (!$integ) continue;
    $creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
    $access_token = trim($creds['access_token'] ?? '');
    if ($post['platform'] === 'tiktok') {
        $access_token = tiktok_get_fresh_token($pdo, $integ['id'], $creds) ?? $access_token;
    }
    if (!$access_token) continue;

    $postId = $post['external_post_id'];
    $likes = $comments = $shares = $reach = null;

    if ($post['platform'] === 'tiktok') {
        // external_post_id here is the publish_id tiktok_publish_video()
        // stored, but /video/query/ needs TikTok's own video_id — those
        // only coincide when the response's publicaly_available_post_id
        // was captured at publish time (see tiktok_publish_video()), which
        // is what gets saved as external_post_id by auto-publish.php /
        // the app's own publish flow. If a post predates that, try to
        // resolve the real id now (TikTok may have finished processing it
        // since publish time) before giving up.
        if (tiktok_looks_like_publish_id($postId)) {
            $resolved = tiktok_resolve_video_id($access_token, $postId);
            if ($resolved) {
                $postId = $resolved;
                $pdo->prepare("UPDATE posts SET external_post_id = :ext WHERE id = :id")->execute([':ext' => $postId, ':id' => $post['id']]);
            } else {
                continue;
            }
        }
        [$code, $resp] = tiktok_video_insights($access_token, $postId);
        if ($code === 200) {
            $v = $resp['data']['videos'][0] ?? null;
            if ($v) {
                $likes    = $v['like_count'] ?? null;
                $comments = $v['comment_count'] ?? null;
                $shares   = $v['share_count'] ?? null;
                $reach    = $v['view_count'] ?? null;
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
        // Post-level reach needs the Insights endpoint separately.
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

    if ($likes === null && $comments === null && $reach === null) continue;
    $update->execute([
        ':likes' => $likes, ':comments' => $comments, ':shares' => $shares, ':reach' => $reach,
        ':id' => $post['id'],
    ]);
    $updated++;
}

echo "Post insights updated: {$updated} of " . count($rows) . " eligible posts\n";
