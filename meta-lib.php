<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
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
function meta_publish($platform, $page_id, $access_token, $message, $image_url, $scheduled_at = null, $story_image_url = null, $post_type = null, $cover_url = null, $image_urls = null) {
    $v = META_GRAPH_VERSION;
    $isCarousel = $post_type === 'carousel' && is_array($image_urls) && count($image_urls) > 1;

    if ($platform === 'facebook') {
        if ($isCarousel) {
            return fb_publish_carousel($v, $page_id, $access_token, $message, $image_urls, $scheduled_at);
        }
        $isFbVideo = in_array($post_type, ['reel', 'video'], true);
        if ($isFbVideo && $image_url) {
            // Facebook video/reel: use the /videos endpoint with file_url
            $endpoint  = "https://graph.facebook.com/{$v}/{$page_id}/videos";
            $post_data = ['file_url' => $image_url, 'description' => $message, 'access_token' => $access_token];
            $videoTimeout = 300;   // big videos: Facebook can take minutes to fetch the file; a short timeout here would count as a failure and retry (a duplicate)
        } elseif ($image_url) {
            $endpoint  = "https://graph.facebook.com/{$v}/{$page_id}/photos";
            $post_data = ['url' => $image_url, 'caption' => $message, 'access_token' => $access_token];
            if (($fixed = fb_fixed_photo($image_url))) { unset($post_data['url']); $post_data['source'] = $fixed; }
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
        return meta_curl($endpoint, $post_data, $videoTimeout ?? null);
    }

    if ($platform === 'instagram') {
        if ($isCarousel) {
            return ig_publish_carousel($v, $page_id, $access_token, $message, $image_urls);
        }
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
function ig_create_container($v, $ig_user_id, $access_token, $media_url, $caption, $media_type, $cover_url = null, $is_carousel_item = false) {
    $container_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media";
    $url_key = ($media_type === 'REELS') ? 'video_url' : 'image_url';
    $container_data = [$url_key => $media_url, 'access_token' => $access_token];
    if ($caption)    $container_data['caption']    = $caption;
    if ($media_type) $container_data['media_type'] = $media_type;
    if ($cover_url)  $container_data['cover_url']  = $cover_url;
    if ($is_carousel_item) $container_data['is_carousel_item'] = 'true';
    return meta_curl($container_ep, $container_data, $media_type === 'REELS' ? 120 : null);
}

// Instagram carousel (2-10 images/videos in one post, published in the
// exact order given — see DesignAssetGrid's drag/arrow reordering on the
// frontend, which IS this order). Each slide is first created as its own
// "child" container (is_carousel_item=true, no caption of its own), then
// a parent container of media_type=CAROUSEL references all the child ids
// and carries the actual caption; that parent is what actually gets
// polled and published, same as a normal single-image post.
function ig_publish_carousel($v, $ig_user_id, $access_token, $caption, $image_urls) {
    $image_urls = array_slice($image_urls, 0, 10); // Instagram's own carousel limit
    $children = [];
    foreach ($image_urls as $url) {
        [$code, $resp] = ig_create_container($v, $ig_user_id, $access_token, $url, null, null, null, true);
        if ($code !== 200 || empty($resp['id'])) {
            return [$code ?: 502, ['error' => 'Failed to create carousel slide container', 'detail' => $resp]];
        }
        $children[] = $resp['id'];
    }

    $container_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media";
    [$code, $resp] = meta_curl($container_ep, [
        'media_type' => 'CAROUSEL',
        'children' => implode(',', $children),
        'caption' => $caption ?: '',
        'access_token' => $access_token,
    ]);
    if ($code !== 200 || empty($resp['id'])) {
        return [$code ?: 502, ['error' => 'Failed to create carousel container', 'detail' => $resp]];
    }

    $status_ep = "https://graph.instagram.com/{$v}/{$resp['id']}?" . http_build_query([
        'fields' => 'status_code,status', 'access_token' => $access_token,
    ]);
    for ($i = 0; $i < 12; $i++) {
        usleep(($i === 0 ? 500 : 1500) * 1000);
        [, $statusResp] = meta_curl_get($status_ep);
        $status = $statusResp['status_code'] ?? null;
        if ($status === 'FINISHED') break;
        if ($status === 'ERROR') return [502, ['error' => 'Instagram failed to process the carousel', 'detail' => $statusResp]];
    }

    $publish_ep = "https://graph.instagram.com/{$v}/{$ig_user_id}/media_publish";
    return meta_curl($publish_ep, ['creation_id' => $resp['id'], 'access_token' => $access_token]);
}

// Facebook multi-photo post: each image is first uploaded "unpublished"
// (published=false, just registers it and returns a photo id, doesn't
// post anything on its own), then one /feed post attaches all of them via
// attached_media, in the same order they were uploaded — that's what
// actually makes it render as one multi-photo post instead of N separate
// single-photo posts.
function fb_publish_carousel($v, $page_id, $access_token, $message, $image_urls, $scheduled_at = null) {
    $image_urls = array_slice($image_urls, 0, 10);
    $attached = [];
    foreach ($image_urls as $url) {
        $photo_ep = "https://graph.facebook.com/{$v}/{$page_id}/photos";
        $photo_fields = ['url' => $url, 'published' => 'false', 'access_token' => $access_token];
        if (($fixed = fb_fixed_photo($url))) { unset($photo_fields['url']); $photo_fields['source'] = $fixed; }
        [$code, $resp] = meta_curl($photo_ep, $photo_fields);
        if ($code !== 200 || empty($resp['id'])) {
            return [$code ?: 502, ['error' => 'Failed to upload carousel photo', 'detail' => $resp]];
        }
        $attached[] = $resp['id'];
    }

    $feed_ep = "https://graph.facebook.com/{$v}/{$page_id}/feed";
    $post_data = ['message' => $message ?: '', 'access_token' => $access_token];
    foreach (array_values($attached) as $i => $photoId) {
        $post_data["attached_media[{$i}]"] = json_encode(['media_fbid' => $photoId]);
    }
    if ($scheduled_at) {
        $ts = strtotime($scheduled_at);
        if ($ts && $ts > time()) {
            $post_data['published'] = 'false';
            $post_data['scheduled_publish_time'] = $ts;
        }
    }
    return meta_curl($feed_ep, $post_data);
}

// Polls a container's status_code once and, if FINISHED, calls media_publish.
// Returns one of:
//   [200, {id, ...}]            — published successfully
//   [202, {status:"processing"}] — still processing, call again
//   [502, {error, ...}]          — Meta reported ERROR or cURL failed
function ig_poll_and_publish($v, $ig_user_id, $access_token, $container_id) {
    $status_ep = "https://graph.instagram.com/{$v}/{$container_id}?" . http_build_query([
        'fields' => 'status_code,status', 'access_token' => $access_token,
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
        'fields' => 'status_code,status', 'access_token' => $access_token,
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

/**
 * Facebook rejects photos over 10 MB or in formats it can't read (subcode 1366046, "Can't Read Files").
 * Checks the image first; if it's too big / the wrong type, downloads it, shrinks it to a JPEG with GD and
 * returns a CURLFile to upload directly instead of the URL. Returns null when the URL is fine as it is.
 */
function fb_fixed_photo($url) {
    if (!function_exists('imagecreatefromstring')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    $len = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD); $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    $okType = $type === '' || preg_match('~image/(jpeg|jpg|png|gif|webp|tiff|heif|heic)~', $type);
    if ($okType && $len > 0 && $len < 9 * 1024 * 1024) return null; // fine as it is
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $bin = curl_exec($ch); curl_close($ch);
    if (!$bin) return null;
    if (strlen($bin) < 9 * 1024 * 1024 && $okType) return null;
    $img = @imagecreatefromstring($bin); unset($bin);
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img); $max = 2048;
    if ($w > $max || $h > $max) { $r = min($max / $w, $max / $h); $n = imagescale($img, (int)($w * $r), (int)($h * $r)); if ($n) { imagedestroy($img); $img = $n; } }
    $tmp = tempnam(sys_get_temp_dir(), 'fbimg') . '.jpg';
    imagejpeg($img, $tmp, 85); imagedestroy($img);
    register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
    return new CURLFile($tmp, 'image/jpeg', 'photo.jpg');
}

function meta_curl($endpoint, $post_data, $timeout = null) {
    $multipart = false;
    foreach ($post_data as $v) { if ($v instanceof CURLFile) { $multipart = true; break; } }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $multipart ? $post_data : http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => $timeout ?: ($multipart ? 90 : 20),
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return [502, ['error' => "cURL error: {$curl_err}"]];
    return [$http_code, json_decode($response, true) ?: ['raw' => $response]];
}
