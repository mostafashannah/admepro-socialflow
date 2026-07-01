<?php
// ================================================================
// SocialFlow — shared Meta Graph API publish logic.
// Used by both social-publish.php (manual "Publish Now" button) and
// auto-publish.php (scheduled cron). Keep Graph API version + request
// shape in one place so both stay in sync.
// ================================================================

define('META_GRAPH_VERSION', 'v23.0');

// Performs a Facebook or Instagram publish. Returns [http_code, decoded_response].
// $story_image_url is Instagram-only: when set, a Story is published in addition
// to the regular feed post, using the same container → poll → media_publish flow
// but with media_type=STORIES. Failure to publish the story does not fail the
// whole request — it's reported back under the "story" key of the response.
// For REELS, returns [202, {status:"processing", container_id, ig_user_id}] so
// the caller can hand the container_id back to the client for async polling
// (avoids nginx gateway timeout on slow Meta video processing).
function meta_publish($platform, $page_id, $access_token, $message, $image_url, $scheduled_at = null, $story_image_url = null, $post_type = null, $cover_url = null) {
    $v = META_GRAPH_VERSION;

    if ($platform === 'facebook') {
        $isFbVideo = in_array($post_type, ['reel', 'video'], true);
        if ($isFbVideo && $image_url) {
            // Facebook video/reel: use the /videos endpoint with file_url
            $endpoint  = "https://graph.facebook.com/{$v}/{$page_id}/videos";
            $post_data = ['file_url' => $image_url, 'description' => $message, 'access_token' => $access_token];
        } elseif ($image_url) {
            $endpoint  = "https://graph.facebook.com/{$v}/{$page_id}/photos";
            $post_data = ['url' => $image_url, 'caption' => $message, 'access_token' => $access_token];
        } else {
            $endpoint  = "https://graph.facebook.com/{$v}/{$page_id}/feed";
            $post_data = ['message' => $message, 'access_token' => $access_token];
        }
        if ($scheduled_at) {
            $ts = strtotime($scheduled_at);
            if ($ts && $ts > time()) {
                $post_data['published']              = 'false';
                $post_data['scheduled_publish_time']  = $ts;
            }
        }
        return meta_curl($endpoint, $post_data);
    }

    if ($platform === 'instagram') {
        $isReel = in_array($post_type, ['reel', 'video'], true);
        if (!$image_url) return [400, ['error' => 'Instagram requires image_url (or video_url for reels)']];

        if ($isReel) {
            // Reels: create the container only and return immediately so the
            // HTTP response goes back to the client before nginx times out.
            // The client polls /reel-status.php to check when Meta has finished
            // processing the video and trigger the final media_publish step.
            [$code, $resp] = ig_create_container($v, $page_id, $access_token, $image_url, $message, 'REELS', $cover_url);
            if ($code !== 200 || empty($resp['id'])) {
                return [$code ?: 502, ['error' => 'Failed to create reel container', 'detail' => $resp]];
            }
            return [202, ['status' => 'processing', 'container_id' => $resp['id'], 'ig_user_id' => $page_id]];
        }

        // The Instagram "Connect" flow (meta-oauth-callback.php) uses Instagram API
        // with Instagram Login, which issues graph.instagram.com-scoped tokens and
        // account IDs — graph.facebook.com cannot parse those tokens at all (hence
        // the misleading "Cannot parse access token" error), so publishing must go
        // through graph.instagram.com too, not graph.facebook.com.
        [$code, $resp] = ig_publish_media($v, $page_id, $access_token, $image_url, $message, null);
        if ($code !== 200) return [$code, $resp];

        if ($story_image_url) {
            [$storyCode, $storyResp] = ig_publish_media($v, $page_id, $access_token, $story_image_url, null, 'STORIES');
            $resp['story'] = $storyCode === 200 ? $storyResp : ['error' => 'Story publish failed', 'detail' => $storyResp];
        }
        return [200, $resp];
    }

    return [400, ['error' => 'Unsupported platform. Supported: facebook, instagram']];
}

// Creates an Instagram media container without polling or publishing.
// Returns [http_code, response] — on success response contains 'id'.
function ig_create_container($v, $ig_user_id, $access_token, $media_url, $caption, $media_type, $cover_url = null) {
    $container_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media";
    $url_key = ($media_type === 'REELS') ? 'video_url' : 'image_url';
    $container_data = [$url_key => $media_url, 'access_token' => $access_token];
    if ($caption)    $container_data['caption']    = $caption;
    if ($media_type) $container_data['media_type'] = $media_type;
    if ($cover_url)  $container_data['cover_url']  = $cover_url;
    return meta_curl($container_ep, $container_data);
}

// Polls a container's status_code once and, if FINISHED, calls media_publish.
// Returns one of:
//   [200, {id, ...}]            — published successfully
//   [202, {status:"processing"}] — still processing, call again
//   [502, {error, ...}]          — Meta reported ERROR or cURL failed
function ig_poll_and_publish($v, $ig_user_id, $access_token, $container_id) {
    $status_ep = "https://graph.instagram.com/{$v}/{$container_id}?" . http_build_query([
        'fields' => 'status_code', 'access_token' => $access_token,
    ]);
    [, $statusResp] = meta_curl_get($status_ep);
    $status = $statusResp['status_code'] ?? null;
    if ($status === 'ERROR') {
        return [502, ['error' => 'Instagram failed to process the media', 'detail' => $statusResp]];
    }
    if ($status !== 'FINISHED') {
        return [202, ['status' => 'processing', 'container_status' => $status]];
    }
    $publish_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media_publish";
    return meta_curl($publish_ep, ['creation_id' => $container_id, 'access_token' => $access_token]);
}

// Creates an Instagram media container (feed post or story), polls until
// processed, then publishes it. Used for images and stories (not reels —
// reels use the async ig_create_container + ig_poll_and_publish pair to
// avoid nginx gateway timeouts during slow video processing).
function ig_publish_media($v, $ig_user_id, $access_token, $image_url, $caption, $media_type) {
    [$code, $resp] = ig_create_container($v, $ig_user_id, $access_token, $image_url, $caption, $media_type);
    if ($code !== 200 || empty($resp['id'])) {
        return [$code ?: 502, ['error' => 'Failed to create media container', 'detail' => $resp]];
    }

    $status_ep = "https://graph.instagram.com/{$v}/{$resp['id']}?" . http_build_query([
        'fields' => 'status_code', 'access_token' => $access_token,
    ]);
    for ($i = 0; $i < 12; $i++) {
        usleep(($i === 0 ? 500 : 1500) * 1000);
        [, $statusResp] = meta_curl_get($status_ep);
        $status = $statusResp['status_code'] ?? null;
        if ($status === 'FINISHED') break;
        if ($status === 'ERROR') {
            return [502, ['error' => 'Instagram failed to process the media', 'detail' => $statusResp]];
        }
    }

    $publish_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media_publish";
    return meta_curl($publish_ep, ['creation_id' => $resp['id'], 'access_token' => $access_token]);
}

function meta_curl_get($endpoint) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return [502, ['error' => "cURL error: {$curl_err}"]];
    return [$http_code, json_decode($response, true) ?: ['raw' => $response]];
}

function meta_curl($endpoint, $post_data) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return [502, ['error' => "cURL error: {$curl_err}"]];
    return [$http_code, json_decode($response, true) ?: ['raw' => $response]];
}
