<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script
// ================================================================
// SocialFlow — Zernio (https://zernio.com) posting API.
// One API key (ZERNIO_API_KEY in config.php, server-side only) posts to every
// social account connected in Zernio. A SocialFlow integration that goes through Zernio is stored as:
//     app_key = linkedin (or another platform), credentials = {page_id: <Zernio account id>, access_token: "zernio"}
// and linkedin_publish() hands those to zernio_publish() below, so manual "Publish" and the scheduled
// auto-publish both work without any other change.
// ================================================================

const ZERNIO_TOKEN_MARKER = 'zernio';

function zernio_enabled() { return defined('ZERNIO_API_KEY') && ZERNIO_API_KEY; }

/** [http_code, decoded body]. $headers = extra "Name: value" lines. */
function zernio_request($method, $path, $body = null, array $headers = []) {
    if (!zernio_enabled()) return [500, ['error' => 'ZERNIO_API_KEY is not set in config.php']];
    $ch = curl_init('https://zernio.com/api/v1' . $path);
    $h = array_merge(['Authorization: Bearer ' . ZERNIO_API_KEY, 'Accept: application/json'], $headers);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($err) return [502, ['error' => 'Zernio request failed: ' . $err]];
    return [$code, json_decode((string)$raw, true) ?: ['raw' => substr((string)$raw, 0, 300)]];
}

function zernio_media_type($url) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'mov', 'm4v', 'avi', 'webm', 'mpeg'], true)) return 'video';
    if ($ext === 'pdf') return 'document';
    return 'image';
}

/**
 * Publish now through Zernio. $platform = zernio platform string (linkedin, twitter, facebook, instagram, tiktok, youtube…).
 * $mediaUrls = public https URLs (images / one video / one document). Returns [http_code, response] in the same shape the
 * other publishers use: 2xx + ['id' => platformPostId, 'post_id' => zernio post id, 'url' => live url], or [code, ['error' => …]].
 */
function zernio_publish($platform, $accountId, $message, $mediaUrls = [], array $platformSpecific = [], array $extra = []) {
    $mediaUrls = array_values(array_filter((array)$mediaUrls));
    $target = ['platform' => $platform, 'accountId' => $accountId];
    if ($platformSpecific) $target['platformSpecificData'] = $platformSpecific;
    $body = ['content' => $message, 'platforms' => [$target], 'publishNow' => true] + $extra;
    if ($mediaUrls) $body['mediaItems'] = array_map(fn($u) => ['type' => zernio_media_type($u), 'url' => $u], $mediaUrls);
    // Same content to the same account within 24h returns the original post instead of posting twice (safe cron retries).
    $idem = 'sf-' . sha1($platform . '|' . $accountId . '|' . $message . '|' . implode(',', $mediaUrls));
    [$code, $resp] = zernio_request('POST', '/posts', $body, ['Idempotency-Key: ' . $idem]);
    if ($code < 200 || $code >= 300) {
        return [$code ?: 502, ['error' => $resp['error'] ?? 'Zernio rejected the post', 'code' => $resp['code'] ?? null, 'detail' => $resp]];
    }
    $post = $resp['post'] ?? $resp;
    $pl = ($post['platforms'][0] ?? []);
    if (($pl['status'] ?? '') === 'failed' || ($post['status'] ?? '') === 'failed') {
        return [502, ['error' => $pl['errorMessage'] ?? 'Zernio reported the post failed', 'detail' => $resp]];
    }
    return [200, [
        'id'      => $pl['platformPostId'] ?? ($post['_id'] ?? null),
        'post_id' => $post['_id'] ?? null,
        'url'     => $pl['platformPostUrl'] ?? null,
        'status'  => $post['status'] ?? ($pl['status'] ?? null),
    ]];
}

/** Reply to a comment through Zernio. $externalId = "<platform post id>||<comment id>" as stored by zernio-webhook.php. */
function zernio_reply_comment($accountId, $externalId, $message) {
    [$postId, $commentId] = array_pad(explode('||', (string)$externalId, 2), 2, '');
    if ($postId === '' || $commentId === '') return [400, ['error' => 'Missing post or comment id for the reply']];
    [$code, $resp] = zernio_request('POST', '/inbox/comments/' . rawurlencode($postId),
        ['accountId' => $accountId, 'commentId' => $commentId, 'message' => $message],
        ['Idempotency-Key: sf-' . sha1($accountId . '|' . $externalId . '|' . $message)]);
    if ($code >= 200 && $code < 300) return [200, $resp];
    return [$code ?: 502, ['error' => $resp['error'] ?? 'Zernio could not post the reply', 'detail' => $resp]];
}
