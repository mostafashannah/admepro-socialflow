<?php
// ================================================================
// SocialFlow — shared TikTok API logic (Content Posting + token refresh).
// Used by social-publish.php (manual "Publish Now"), auto-publish.php
// (scheduled cron), tiktok-refresh-cron.php (keeps access tokens alive),
// and post-insights-cron.php (per-video engagement).
//
// TikTok differs from Meta/LinkedIn in two important ways this file has
// to account for:
//   1. Access tokens expire in ~24h (vs Meta's long-lived tokens) — every
//      caller here must be prepared to refresh first, so publish/insights
//      calls take an $integration_id and refresh-and-persist as needed.
//   2. Publishing a video is asynchronous: /init/ only returns a
//      publish_id, and the actual post only exists once TikTok finishes
//      processing it — tiktok_publish_video() polls /status/fetch/ for
//      the caller so this looks synchronous, same shape as the Reels
//      polling already done in auto-publish.php for Instagram.
// ================================================================

define('TIKTOK_API_BASE', 'https://open.tiktokapis.com/v2');

function tiktok_curl($url, $method, $body, $access_token) {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json; charset=UTF-8'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($curl_err) return [502, ['error' => "cURL error: {$curl_err}"]];
    return [$http_code, json_decode($response, true) ?: ['raw' => $response]];
}

// Exchanges a refresh_token for a new access_token (called by
// tiktok-refresh-cron.php, and lazily by tiktok_get_fresh_token() below).
// Returns ['access_token','refresh_token','expires_at'] or null on failure.
function tiktok_refresh_token($refresh_token) {
    $ch = curl_init(TIKTOK_API_BASE . '/oauth/token/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_key'    => TIKTOK_CLIENT_KEY,
            'client_secret' => TIKTOK_CLIENT_SECRET,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $resp = json_decode($res, true);
    if ($err || empty($resp['access_token'])) return null;
    return [
        'access_token'  => $resp['access_token'],
        'refresh_token' => $resp['refresh_token'] ?? $refresh_token,
        'expires_at'    => date('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 86400) - 300),
    ];
}

// Given an integrations.id, returns a valid access_token — refreshing and
// persisting the new token pair to the DB first if the stored one is
// expired or about to be (within 10 minutes). Every publish/insights call
// in this file should go through this instead of trusting stored creds
// blindly, since a 24h-lived token WILL be stale by the time a scheduled
// post's cron tick or a nightly insights run reaches it.
function tiktok_get_fresh_token(PDO $pdo, string $integration_id, array $creds): ?string {
    $expiresAt = $creds['expires_at'] ?? null;
    $stillValid = $expiresAt && strtotime($expiresAt) > time() + 600;
    if ($stillValid && !empty($creds['access_token'])) return $creds['access_token'];

    if (empty($creds['refresh_token'])) return $creds['access_token'] ?: null;
    $fresh = tiktok_refresh_token($creds['refresh_token']);
    if (!$fresh) return $creds['access_token'] ?: null; // couldn't refresh — try the old one, let the API call itself fail clearly

    $newCreds = array_merge($creds, $fresh);
    $upd = $pdo->prepare("UPDATE integrations SET credentials = :c WHERE id = :id");
    $upd->execute([':c' => json_encode($newCreds), ':id' => $integration_id]);
    return $fresh['access_token'];
}

// Publishes a video by URL (PULL_FROM_URL source — TikTok fetches it
// directly from your own storage, same as how the app already hosts
// design_urls for Facebook/Instagram/LinkedIn). Polls publish status
// synchronously since cron/manual-publish callers here aren't constrained
// by a short web-request timeout. Returns [http_code, decoded_response]
// with 'id' set to the publish_id on success, matching the shape
// linkedin_publish()/meta_publish() already return.
function tiktok_publish_video($access_token, $video_url, $caption) {
    $body = [
        'post_info' => [
            'title'            => mb_substr($caption, 0, 2200),
            'privacy_level'    => 'SELF_ONLY', // safest default until an audited app can request PUBLIC_TO_EVERYONE per-account consent; override via config if your app is approved
            'disable_duet'     => false,
            'disable_comment'  => false,
            'disable_stitch'   => false,
        ],
        'source_info' => [
            'source'     => 'PULL_FROM_URL',
            'video_url'  => $video_url,
        ],
    ];
    if (defined('TIKTOK_PRIVACY_LEVEL') && TIKTOK_PRIVACY_LEVEL) {
        $body['post_info']['privacy_level'] = TIKTOK_PRIVACY_LEVEL;
    }

    [$code, $resp] = tiktok_curl(TIKTOK_API_BASE . '/post/publish/video/init/', 'POST', $body, $access_token);
    $publishId = $resp['data']['publish_id'] ?? null;
    if ($code !== 200 || !$publishId) {
        return [$code ?: 502, ['error' => $resp['error']['message'] ?? 'Failed to start TikTok video upload']];
    }

    // Poll for completion — TikTok processes the pulled video async.
    for ($i = 0; $i < 30; $i++) {
        sleep(4);
        [$sCode, $sResp] = tiktok_curl(TIKTOK_API_BASE . '/post/publish/status/fetch/', 'POST', ['publish_id' => $publishId], $access_token);
        $status = $sResp['data']['status'] ?? '';
        if ($status === 'PUBLISH_COMPLETE') {
            return [200, ['id' => $publishId, 'video_id' => $sResp['data']['publicaly_available_post_id'][0] ?? $publishId]];
        }
        if ($status === 'FAILED') {
            return [502, ['error' => $sResp['data']['fail_reason'] ?? 'TikTok reported the upload failed']];
        }
        if ($sCode !== 200) break;
    }
    return [202, ['id' => $publishId, 'status' => 'processing', 'note' => 'TikTok is still processing this video — check the TikTok app; it will appear once done.']];
}

// Per-video engagement, keyed by TikTok's own video id (not the
// publish_id publishing returns — see the note in post-insights-cron.php
// about needing publicaly_available_post_id instead).
function tiktok_video_insights($access_token, $video_id) {
    $fields = 'id,like_count,comment_count,share_count,view_count';
    $url = TIKTOK_API_BASE . '/video/query/?fields=' . urlencode($fields);
    return tiktok_curl($url, 'POST', ['filters' => ['video_ids' => [$video_id]]], $access_token);
}
