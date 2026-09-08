<?php
/**
 * post-insights-lib.php — shared per-platform engagement fetch used by both
 * post-insights-fetch.php (on-demand "Refresh Now" button) and
 * post-insights-cron.php (daily background refresh). Previously duplicated
 * near-identically in both files; pulled out so a fix in one always applies
 * to the other too.
 */

if (!function_exists('graph_get')) {
    function graph_get($url, $params) {
        $qs = http_build_query($params);
        $ch = curl_init("$url?$qs");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($res, true)];
    }
}

/**
 * Fetches one platform's engagement for one post.
 * Returns ['likes'=>?int,'comments'=>?int,'shares'=>?int,'reach'=>?int,
 *          'reach_unavailable'=>bool,'error'=>?string,'resolved_post_id'=>?string].
 */
function fetch_one_platform_insights(PDO $pdo, array $post, string $platform, string $postId): array {
    $v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';
    $out = ['likes'=>null,'comments'=>null,'shares'=>null,'reach'=>null,'reach_unavailable'=>false,'error'=>null,'resolved_post_id'=>null];

    $integStmt = $pdo->prepare(
        "SELECT id, credentials FROM integrations WHERE status = 'active' AND app_key = :platform
           AND (client_id = :client_id OR client_id IS NULL OR client_id = '')
         ORDER BY (client_id = :client_id2) DESC LIMIT 1"
    );
    $integStmt->execute([':platform' => $platform, ':client_id' => $post['client_id'], ':client_id2' => $post['client_id']]);
    $integ = $integStmt->fetch(PDO::FETCH_ASSOC);
    if (!$integ) { $out['error'] = "No active integration for {$platform}"; return $out; }

    $creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
    $access_token = trim($creds['access_token'] ?? '');
    if ($platform === 'tiktok') {
        $access_token = tiktok_get_fresh_token($pdo, $integ['id'], $creds) ?? $access_token;
    }
    if (!$access_token) { $out['error'] = "Integration has no access token"; return $out; }
    if (!$postId) { $out['error'] = "No external post ID recorded for {$platform}"; return $out; }

    $graphError = null;
    if ($platform === 'tiktok') {
        if (tiktok_looks_like_publish_id($postId)) {
            $resolved = tiktok_resolve_video_id($access_token, $postId);
            if ($resolved) { $postId = $resolved; $out['resolved_post_id'] = $resolved; }
            else { $out['error'] = "TikTok hasn't made this video's public ID available yet"; return $out; }
        }
        [$code, $resp] = tiktok_video_insights($access_token, $postId);
        if ($code === 200) {
            $vd = $resp['data']['videos'][0] ?? null;
            if ($vd) {
                $out['likes'] = $vd['like_count'] ?? null;
                $out['comments'] = $vd['comment_count'] ?? null;
                $out['shares'] = $vd['share_count'] ?? null;
                $out['reach'] = $vd['view_count'] ?? null;
            }
        } else { $graphError = 'TikTok API error'; }
    } elseif ($platform === 'facebook') {
        // Reels/videos are published to the /videos endpoint, returning a
        // Video node id — Video nodes have no 'shares' field and don't
        // support post_impressions_unique, so a plain feed-post field set
        // 400s the ENTIRE request on them, not just the unsupported part.
        $isVideo = in_array($post['post_type'] ?? '', ['reel','video'], true);
        $fields = $isVideo ? "likes.summary(true),comments.summary(true)" : "likes.summary(true),comments.summary(true),shares";
        [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$postId}", ["fields" => $fields, "access_token" => $access_token]);
        if ($code === 200) {
            $out['likes'] = $resp['likes']['summary']['total_count'] ?? null;
            $out['comments'] = $resp['comments']['summary']['total_count'] ?? null;
            $out['shares'] = $isVideo ? null : ($resp['shares']['count'] ?? 0);
        } else {
            $graphError = $resp['error']['message'] ?? null;
        }
        if ($isVideo) {
            // A Facebook Reel's video-node id doesn't expose the /insights
            // edge at all (confirmed via a live "Tried accessing
            // nonexisting field (insights)" response) — known-unavailable,
            // not a real error.
            $out['reach'] = null;
            $out['reach_unavailable'] = true;
        } else {
            [$rcode, $rresp] = graph_get("https://graph.facebook.com/{$v}/{$postId}/insights", ["metric" => "post_impressions_unique", "access_token" => $access_token]);
            if ($rcode === 200) $out['reach'] = $rresp['data'][0]['values'][0]['value'] ?? null;
            elseif (empty($graphError)) $graphError = $rresp['error']['message'] ?? null;
        }
    } else { // instagram
        $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';
        [$code, $resp] = graph_get("https://{$ig_host}/{$v}/{$postId}", ["fields" => "like_count,comments_count", "access_token" => $access_token]);
        if ($code === 200) {
            $out['likes'] = $resp['like_count'] ?? null;
            $out['comments'] = $resp['comments_count'] ?? null;
        } else {
            $graphError = $resp['error']['message'] ?? null;
        }
        [$rcode, $rresp] = graph_get("https://{$ig_host}/{$v}/{$postId}/insights", ["metric" => "reach", "access_token" => $access_token]);
        if ($rcode === 200) $out['reach'] = $rresp['data'][0]['values'][0]['value'] ?? null;
        elseif (empty($graphError)) $graphError = $rresp['error']['message'] ?? null;
    }
    $out['error'] = $graphError;
    return $out;
}

/**
 * Fetches every platform a post was published to and writes the combined
 * result (per-platform breakdown in insights_by_platform, summed totals in
 * the legacy insight_* columns) back to the posts row. Returns
 * ['ok'=>bool,'by_platform'=>[...],'errors'=>[platform=>msg]].
 */
function refresh_post_insights_all_platforms(PDO $pdo, array $post): array {
    $platforms = json_decode($post['platforms'] ?? '[]', true) ?: [];
    if (!$platforms) $platforms = array_filter([$post['platform'] ?? null]);
    $platformPostIds = json_decode($post['platform_post_ids'] ?? '{}', true) ?: [];
    $byPlatform = json_decode($post['insights_by_platform'] ?? '{}', true) ?: [];

    $anyOk = false;
    $errors = [];
    foreach ($platforms as $platform) {
        $postId = $platformPostIds[$platform] ?? ($platform === ($post['platform'] ?? null) ? ($post['external_post_id'] ?? null) : null);
        $result = fetch_one_platform_insights($pdo, $post, $platform, (string) $postId);
        if ($result['resolved_post_id']) {
            $platformPostIds[$platform] = $result['resolved_post_id'];
            if ($platform === ($post['platform'] ?? null)) {
                $pdo->prepare("UPDATE posts SET external_post_id = :ext WHERE id = :id")->execute([':ext' => $result['resolved_post_id'], ':id' => $post['id']]);
            }
        }
        if ($result['likes'] !== null || $result['comments'] !== null || $result['reach'] !== null) {
            $anyOk = true;
            $byPlatform[$platform] = ['likes'=>$result['likes'], 'comments'=>$result['comments'], 'shares'=>$result['shares'], 'reach'=>$result['reach'], 'reach_unavailable'=>$result['reach_unavailable'], 'fetched_at'=>date('c')];
        } elseif ($result['error']) {
            $errors[$platform] = $result['error'];
        }
    }

    if (!$anyOk) return ['ok'=>false, 'by_platform'=>$byPlatform, 'errors'=>$errors];

    $sumLikes = $sumComments = $sumShares = $sumReach = null;
    $anyShares = $anyReach = false;
    foreach ($byPlatform as $p) {
        if (($p['likes'] ?? null) !== null) $sumLikes = ($sumLikes ?? 0) + $p['likes'];
        if (($p['comments'] ?? null) !== null) $sumComments = ($sumComments ?? 0) + $p['comments'];
        if (($p['shares'] ?? null) !== null) { $sumShares = ($sumShares ?? 0) + $p['shares']; $anyShares = true; }
        if (($p['reach'] ?? null) !== null) { $sumReach = ($sumReach ?? 0) + $p['reach']; $anyReach = true; }
    }

    $pdo->prepare(
        "UPDATE posts SET insight_likes = :likes, insight_comments = :comments, insight_shares = :shares,
           insight_reach = :reach, insight_fetched_at = NOW(), platform_post_ids = :ppi, insights_by_platform = :ibp WHERE id = :id"
    )->execute([
        ':likes'=>$sumLikes, ':comments'=>$sumComments, ':shares'=>$anyShares?$sumShares:null, ':reach'=>$anyReach?$sumReach:null,
        ':ppi'=>json_encode($platformPostIds), ':ibp'=>json_encode($byPlatform), ':id'=>$post['id'],
    ]);

    return ['ok'=>true, 'by_platform'=>$byPlatform, 'errors'=>$errors, 'likes'=>$sumLikes, 'comments'=>$sumComments, 'shares'=>$sumShares, 'reach'=>$sumReach];
}
