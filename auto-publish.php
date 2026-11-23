<?php
/**
 * auto-publish.php — cron script that publishes scheduled posts to
 * Facebook/Instagram automatically, without anyone clicking "Publish Now".
 *
 * Setup on Hostinger (same pattern as brief_reminder.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/auto-publish.php
 *   Schedule: every 5-15 minutes (e.g. star-slash-10 * * * *)
 *
 * Safety:
 *   - Requires AUTO_PUBLISH_ENABLED = true in config.php (kill switch).
 *   - Only acts on posts whose stage is 'scheduled' and whose
 *     scheduled_date/time is due, and only if a matching integration
 *     (app_key = platform) is status='active'.
 *   - Gives up on a post after 3 failed attempts (publish_attempts column)
 *     so a bad token doesn't retry forever every cron tick.
 *   - Can also be run manually for testing: php auto-publish.php
 */

// CLI-only — this script actually publishes to live Facebook/Instagram/
// LinkedIn pages and has no authentication of its own, so it must never be
// reachable over plain HTTP (this file sits in the public web root
// alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';
require_once __DIR__ . '/linkedin-lib.php';
require_once __DIR__ . '/tiktok-lib.php';
require_once __DIR__ . '/publish-alert-lib.php';   // WhatsApp messages: published / could not publish / daily list of posts that need attention

if (!defined('AUTO_PUBLISH_ENABLED') || !AUTO_PUBLISH_ENABLED) {
    echo json_encode(['skipped' => true, 'reason' => 'AUTO_PUBLISH_ENABLED is not true in config.php']);
    exit;
}

// ONE run at a time. A reel can take up to 2 minutes (we wait for the platform to process it) plus the Sara-learning call per
// platform, so on a short cron interval a second run used to start while the first was still going, see the same post still
// "scheduled" and publish it again: that is how one post ended up published several times. The lock is released automatically
// when this process ends, even if it crashes.
$apLock = fopen(sys_get_temp_dir() . '/sf-auto-publish.lock', 'c');
if (!$apLock || !flock($apLock, LOCK_EX | LOCK_NB)) { echo json_encode(['skipped' => true, 'reason' => 'another auto-publish run is still in progress']); exit; }

// Redundant now that config.php itself calls date_default_timezone_set(APP_TIMEZONE)
// for every script that requires it — kept as a defensive fallback in case this
// runs against an older config.php that doesn't do that yet.
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Cairo');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

// Needs migration-publish-pending.sql (posts.publish_pending). Without it reels fall back to waiting inside the run (up to IG_REEL_WAIT_SECONDS).
$hasPending = true;
try { $pdo->query("SELECT publish_pending FROM posts LIMIT 0"); } catch (Throwable $e) { $hasPending = false; }

$now = new DateTime();
// task_type 'grid_layout' (Calendar Plan's Full Grid Layout kind) is a
// design-only deliverable, never actually published to a platform.
// A client with auto_publish_enabled explicitly set to 0 (Settings >
// Scheduling > Auto Publishing) is skipped entirely — their posts still
// go to Scheduled normally, they just always wait for a manual Publish
// Now instead of firing automatically. No client_intelligence row at all
// (never configured) is treated as enabled, same as today's behavior.
$due = $pdo->query(
    "SELECT p.* FROM posts p
     LEFT JOIN client_intelligence ci ON ci.client_id = p.client_id
     WHERE p.stage = 'scheduled' AND (p.task_type IS NULL OR p.task_type <> 'grid_layout')
       AND (ci.auto_publish_enabled IS NULL OR ci.auto_publish_enabled = 1)"
)->fetchAll(PDO::FETCH_ASSOC);

$results = [];

foreach ($due as $post) {
  // One bad post must never stop the others, and must never make a platform that already went out get published again:
  // anything that throws is caught here, written to the post's publish_error (visible in publish-diagnose.php) and counted as a failed attempt.
  try {
    $attempts = (int)($post['publish_attempts'] ?? 0);
    if ($attempts >= 3) continue;

    $dateStr = trim($post['scheduled_date'] ?? '');
    $timeStr = trim($post['scheduled_time'] ?? '') ?: '09:00';
    if (!$dateStr) continue;

    try {
        $scheduledAt = new DateTime("$dateStr $timeStr");
    } catch (Exception $e) {
        continue; // unparsable date — skip, don't crash the whole run
    }
    if ($scheduledAt > $now) continue; // not due yet

    // A post can carry several platforms at once (the in-app Publish button
    // sends to all of them — see connectedMultiPlatforms/handlePublish in
    // app.jsx). This used to read only the single legacy `platform` column,
    // so a post scheduled for Instagram + Facebook silently published to
    // just one of them. Fall back to the legacy single column only when
    // `platforms` was never populated (older posts).
    $allPlatforms = json_decode($post['platforms'] ?? '[]', true) ?: [];
    if (!$allPlatforms) {
        $single = strtolower(trim($post['platform'] ?? ''));
        if ($single) $allPlatforms = [$single];
    }
    $alreadyDone = json_decode($post['published_platforms'] ?? '[]', true) ?: [];
    $targetPlatforms = array_values(array_diff(
        array_filter(array_map('strtolower', array_map('trim', $allPlatforms)), fn($p) => in_array($p, ['facebook', 'instagram', 'linkedin', 'tiktok', 'twitter'], true)),
        $alreadyDone
    ));
    if (!$targetPlatforms) {
        // Every platform already went out but the post never moved to Published (e.g. a run was cut off before the final update):
        // just move it now. Never publishes anything.
        $lowAll = array_values(array_filter(array_map('strtolower', array_map('trim', $allPlatforms))));
        if ($lowAll && !array_diff($lowAll, $alreadyDone)) {
            $ids = json_decode($post['platform_post_ids'] ?? '{}', true) ?: [];
            $pdo->prepare("UPDATE posts SET stage = 'published', published_at = COALESCE(published_at, :now), external_post_id = COALESCE(external_post_id, :ext), publish_error = NULL WHERE id = :id")
                ->execute([':now' => $now->format('Y-m-d H:i:s'), ':ext' => $ids ? reset($ids) : null, ':id' => $post['id']]);
        }
        continue; // nothing valid, or every platform already published
    }

    $anyOk = false;
    $anyAttempted = false;
    $lastExtId = null;
    $errorsByPlatform = [];
    // Multi-platform posts used to only keep the LAST successful platform's
    // id in external_post_id — whichever ran last in the loop below silently
    // overwrote every other platform's id, so a post's Insights fetch (which
    // queries by the post's own `platform` column) could end up looking up a
    // totally different platform's Graph object, surfacing as "Unsupported
    // get request... Object does not exist". Keyed per-platform so each
    // platform's real id survives regardless of publish order.
    $platformPostIds = json_decode($post['platform_post_ids'] ?? '{}', true) ?: [];
    // Reels Instagram is still processing from an earlier run: {instagram:{container_id, ig_user_id, started}}. We only CHECK on them here,
    // we never create a second container for the same post.
    $pending = $hasPending ? (json_decode($post['publish_pending'] ?? '{}', true) ?: []) : [];
    $waiting = false;
    $savePending = function () use ($pdo, $post, &$pending) {
        $pdo->prepare("UPDATE posts SET publish_pending = :p WHERE id = :id")->execute([':p' => $pending ? json_encode($pending) : null, ':id' => $post['id']]);
    };

    foreach ($targetPlatforms as $platform) {

    // Prefer an integration scoped to this post's client; fall back to any
    // active integration for the platform (e.g. a single shared Page).
    $integration = null;
    if (!empty($post['client_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM integrations WHERE app_key = :p AND status = 'active' AND client_id = :cid LIMIT 1");
        $stmt->execute([':p' => $platform, ':cid' => $post['client_id']]);
        $integration = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$integration) {
        $stmt = $pdo->prepare("SELECT * FROM integrations WHERE app_key = :p AND status = 'active' AND (client_id IS NULL OR client_id = '') LIMIT 1");
        $stmt->execute([':p' => $platform]);
        $integration = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$integration) continue; // nothing connected for this platform/client yet

    $creds        = json_decode($integration['credentials'] ?? '{}', true) ?: [];
    $page_id      = trim($creds['page_id']      ?? '');
    $access_token = trim($creds['access_token'] ?? '');
    if ($platform === 'tiktok') {
        // TikTok access tokens expire in ~24h — a scheduled post sitting in
        // the queue for a day would otherwise fail with a stale token even
        // though tiktok-refresh-cron.php keeps the row itself current.
        $access_token = tiktok_get_fresh_token($pdo, $integration['id'], $creds) ?? $access_token;
    }
    if (!$access_token || ($platform !== 'tiktok' && !$page_id)) continue;

    $design_urls   = json_decode($post['design_urls'] ?? '[]', true) ?: [];
    $design_assets = json_decode($post['design_assets'] ?? '[]', true) ?: [];
    $message       = trim(($post['caption'] ?? '') . "\n\n" . ($post['hashtags'] ?? ''));
    // X has its own short caption (280-character limit); the main caption + hashtags only if none was written.
    if ($platform === 'twitter' && trim((string)($post['x_caption'] ?? '')) !== '') $message = trim($post['x_caption']);
    // No X caption and the main one is too long: let Sara summarise it for X (and keep it on the post), instead of failing at 280.
    if ($platform === 'twitter' && trim((string)($post['x_caption'] ?? '')) === '' && mb_strlen($message) > 280) {
        $xc = x_caption_summarize($pdo, $post, $message);
        if ($xc !== null) { $message = $xc; try { $pdo->prepare("UPDATE posts SET x_caption = :x WHERE id = :id")->execute([':x' => $xc, ':id' => $post['id']]); } catch (Throwable $e) {} }
    }
    // Use the LAST file added, not the first — matches publishPost() in app.jsx:
    // when a task has multiple attachments (revisions/replacements), the most
    // recently added one is the intended final version to actually publish.
    $image_url     = end($design_urls) ?: ($design_assets ? (end($design_assets)['url'] ?? '') : '');
    // Carousel — every slide, in the exact order arranged in the Design
    // phase (see DesignAssetGrid's drag/arrow reordering in app.jsx).
    $image_urls    = ($post['post_type'] ?? '') === 'carousel'
        ? array_values(array_filter(array_map(fn($a) => ($a['kind'] ?? '') === 'story' ? null : ($a['url'] ?? null), $design_assets ?: array_map(fn($u) => ['url' => $u], $design_urls))))
        : [];
    $cover_url     = $post['carousel_cover'] ?? '';
    // Tagged kind:"story" by the Ready Content "Also post as Instagram Story" option.
    $story_image_url = '';
    if ($platform === 'instagram') {
        foreach ($design_assets as $asset) {
            if (($asset['kind'] ?? '') === 'story') { $story_image_url = $asset['url'] ?? ''; break; }
        }
    }

    if ($platform === 'instagram' && !empty($pending['instagram']['container_id'])) {
        // A reel already sent to Instagram: just ask how it is going.
        $pc = $pending['instagram'];
        [$code, $resp] = ig_poll_and_publish(META_GRAPH_VERSION, $pc['ig_user_id'] ?? $page_id, $access_token, $pc['container_id']);
        if ($code === 202) {
            $maxMin = defined('IG_REEL_MAX_WAIT_MINUTES') ? max(5, (int)IG_REEL_MAX_WAIT_MINUTES) : 90;
            if (time() - (int)($pc['started'] ?? time()) <= $maxMin * 60) { $waiting = true; continue; }   // still processing: not a failure, check again next run
            unset($pending['instagram']); $savePending();
            $code = 502; $resp = ['error' => 'Reel processing timed out: Instagram was still processing the video after ' . $maxMin . ' minutes.'];
        } elseif ($code !== 200) {
            unset($pending['instagram']); $savePending();
            $detail = trim(($resp['detail']['status_code'] ?? '') . ' ' . ($resp['detail']['status'] ?? '')) ?: ($resp['detail']['error']['message'] ?? ($resp['error'] ?? ''));
            $code = 502; $resp = ['error' => 'Instagram rejected the reel video: ' . (is_string($detail) ? $detail : json_encode($detail)) . ' (check the video format and length, vertical 9:16, H.264 + AAC, under 90 seconds)'];
        } else { unset($pending['instagram']); $savePending(); }
    } elseif ($platform === 'twitter') {
        require_once __DIR__ . '/zernio-lib.php';
        if ($access_token !== ZERNIO_TOKEN_MARKER) { $code = 400; $resp = ['error' => 'X must be connected through Zernio.']; }
        elseif (mb_strlen($message) > 280) { $code = 422; $resp = ['error' => 'X posts are limited to 280 characters (this one is ' . mb_strlen($message) . ').']; }
        else [$code, $resp] = zernio_publish('twitter', $page_id, $message, $image_url ? [$image_url] : []);
    } elseif ($platform === 'linkedin') {
        [$code, $resp] = linkedin_publish($page_id, $access_token, $message, $image_url);
    } elseif ($platform === 'tiktok') {
        if (!$image_url) {
            $anyAttempted = true;
            $errorsByPlatform[$platform] = 'TikTok requires a video file attached to this post';
            continue;
        }
        // Scheduled posts can't ask anyone on the spot, so TikTok's required choices (visibility, comments, duet, stitch)
        // come from what an admin picked once in this integration's settings (config.tiktok_*).
        $tcfg = json_decode($integration['config'] ?? '{}', true) ?: [];
        if (empty($tcfg['tiktok_privacy_level'])) {
            $anyAttempted = true;
            $errorsByPlatform[$platform] = 'Choose a default TikTok visibility for scheduled posts: Integrations → TikTok → edit → "Default visibility".';
            continue;
        }
        [$code, $resp] = tiktok_publish_video($access_token, $image_url, $message, [
            'privacy_level' => $tcfg['tiktok_privacy_level'],
            'allow_comment' => !empty($tcfg['tiktok_allow_comment']),
            'allow_duet'    => !empty($tcfg['tiktok_allow_duet']),
            'allow_stitch'  => !empty($tcfg['tiktok_allow_stitch']),
        ]);
    } else {
        [$code, $resp] = meta_publish($platform, $page_id, $access_token, $message, $image_url, null, $story_image_url ?: null, $post['post_type'] ?? null, $cover_url ?: null, $image_urls ?: null);
    }

    // Reels return 202 with a container_id; poll synchronously here since the
    // cron job is not constrained by nginx timeouts.
    if ($code === 202 && ($resp['status'] ?? '') === 'processing') {
        $container_id = $resp['container_id'] ?? '';
        $ig_user_id   = $resp['ig_user_id']   ?? $page_id;
        if ($hasPending && $platform === 'instagram' && $container_id !== '') {
            // No standing around: remember the container and let the next runs check on it. A video can take as long as Instagram needs.
            $pending['instagram'] = ['container_id' => $container_id, 'ig_user_id' => $ig_user_id, 'started' => time()];
            $savePending();
            $waiting = true; continue;
        }
        $published = false;
        // Instagram can take several minutes to process a reel. Wait up to IG_REEL_WAIT_SECONDS (default 9 minutes; set it in config.php
        // to change). The run holds the lock meanwhile, so another run can't start and double-publish; the next tick simply skips.
        $waitSecs = defined('IG_REEL_WAIT_SECONDS') ? max(60, (int)IG_REEL_WAIT_SECONDS) : 540;
        $started = time();
        while (true) {
            sleep(5);
            [$code, $resp] = ig_poll_and_publish(META_GRAPH_VERSION, $ig_user_id, $access_token, $container_id);
            if ($code === 200) { $published = true; break; }
            if ($code !== 202) break;                                   // Instagram reported an error: more waiting won't help
            if (time() - $started >= $waitSecs) break;                  // still processing when the time ran out
        }
        if (!$published && $code !== 200) {
            if ($code === 202) {
                $code = 502;
                $resp = ['error' => 'Reel processing timed out: Instagram was still processing the video after ' . round((time() - $started) / 60) . ' minutes.'];
            } else {
                // Instagram answered with a real error about the video itself (format, length, size...). Keep its words.
                $detail = $resp['detail']['status_code'] ?? ($resp['detail']['error']['message'] ?? ($resp['error'] ?? ''));
                $resp = ['error' => 'Instagram rejected the reel video: ' . (is_string($detail) ? $detail : json_encode($detail)) . ' (check the video format and length, vertical 9:16, H.264 + AAC, under 90 seconds)'];
            }
        }
    }

    $ok = $code >= 200 && $code < 300;
    $anyAttempted = true;

    if ($ok) {
        $anyOk = true;
        $alreadyDone[] = $platform;
        // TikTok's publish_id ($resp['id']) can't be looked up by the
        // insights API — video_id (only present once TikTok finishes
        // processing) is what post-insights-cron.php needs stored instead.
        $lastExtId = $resp['video_id'] ?? $resp['id'] ?? $resp['post_id'] ?? $lastExtId;
        if ($lastExtId) $platformPostIds[$platform] = $lastExtId;
        // Save this platform as done RIGHT NOW (not only at the end of the post): if this run is cut off before the final
        // update, the next run must never publish this platform again.
        try {
            $pdo->prepare("UPDATE posts SET published_platforms = :pp, platform_post_ids = :ppi WHERE id = :id")
                ->execute([':pp' => json_encode(array_values(array_unique($alreadyDone))), ':ppi' => json_encode($platformPostIds), ':id' => $post['id']]);
        } catch (Throwable $e) { error_log('[auto-publish] could not save published platform for ' . $post['id'] . ': ' . $e->getMessage()); }
        sara_learn_from_publish($pdo, $post, $platform);
    } else {
        $errorsByPlatform[$platform] = $resp;
    }

    $logStmt = $pdo->prepare(
        "INSERT INTO integration_logs (id, integration_id, integration_name, status, error, payload_summary, triggered_by)
         VALUES (UUID(), :iid, :iname, :status, :error, :summary, 'auto-publish-cron')"
    );
    $logStmt->execute([
        ':iid'     => $integration['id'],
        ':iname'   => $integration['name'],
        ':status'  => $ok ? 'success' : 'error',
        ':error'   => $ok ? null : json_encode($resp),
        ':summary' => "Auto-publish post {$post['id']} ({$platform})",
    ]);

    $runUpd = $pdo->prepare(
        "UPDATE integrations SET last_run_at = :now, last_run_status = :status, last_run_message = :msg,
         run_count = run_count + 1, error_count = IF(:errzero = 0, 0, error_count + :errinc) WHERE id = :iid"
    );
    $runUpd->execute([
        ':now'    => $now->format('Y-m-d H:i:s'),
        ':status' => $ok ? 'success' : 'error',
        ':msg'    => $ok ? "Published post {$post['id']}" : "Failed to publish post {$post['id']}",
        ':errinc' => $ok ? 0 : 1,
        ':errzero' => $ok ? 0 : 1,   // same value, separate name: PDO with native prepares forbids reusing one named parameter
        ':iid'    => $integration['id'],
    ]);

    $results[] = ['post_id' => $post['id'], 'platform' => $platform, 'ok' => $ok, 'http_code' => $code];
    } // end foreach $targetPlatforms

    if (!$anyAttempted) continue; // no integration connected for any target platform yet

    // Fully done once every platform on this post has succeeded at some
    // point (this run or an earlier one) — only then does the post itself
    // move to Published, matching the in-app Publish button's per-platform
    // behavior (Instagram + Facebook both have to land before the card
    // moves out of Content/Scheduled).
    $stillMissing = array_diff($allPlatforms, $alreadyDone);
    if (!$stillMissing) {
        $upd = $pdo->prepare("UPDATE posts SET stage = 'published', published_at = :now, external_post_id = :ext, published_platforms = :pp, platform_post_ids = :ppi, publish_error = NULL WHERE id = :id");
        $upd->execute([
            ':now' => $now->format('Y-m-d H:i:s'), ':ext' => $lastExtId,
            ':pp' => json_encode(array_values(array_unique($alreadyDone))),
            ':ppi' => json_encode($platformPostIds), ':id' => $post['id'],
        ]);
        paNotifyPublished($pdo, $post, array_values(array_unique($alreadyDone)));
    } else {
        // Partial progress (e.g. Instagram went out, Facebook failed) is
        // saved either way so a retry never re-posts to a platform that
        // already succeeded — only the attempt counter advances toward the
        // 3-try cutoff, and only for the platform(s) still failing.
        // A video Instagram rejected fails identically every time: give up now instead of repeating it three times.
        $hardFail = false; foreach ($errorsByPlatform as $e) { if (strpos(json_encode($e), 'rejected the reel video') !== false) $hardFail = true; }
        $newAttempts = $hardFail ? 3 : $attempts + ($errorsByPlatform ? 1 : 0);
        $upd = $pdo->prepare("UPDATE posts SET publish_attempts = :att, publish_error = :err, published_platforms = :pp, platform_post_ids = :ppi WHERE id = :id");
        $upd->execute([
            ':att' => $newAttempts, ':err' => json_encode($errorsByPlatform),   // waiting for a reel is not a failed attempt
            ':pp' => json_encode(array_values(array_unique($alreadyDone))),
            ':ppi' => json_encode($platformPostIds), ':id' => $post['id'],
        ]);
        if ($newAttempts >= 3 && $errorsByPlatform && $attempts < 3) paNotifyGaveUp($pdo, $post, $errorsByPlatform, array_values(array_unique($alreadyDone)));
    }
  } catch (Throwable $e) {
    error_log('[auto-publish] post ' . ($post['id'] ?? '?') . ' crashed: ' . $e->getMessage());
    try {
        $pdo->prepare("UPDATE posts SET publish_attempts = :att, publish_error = :err WHERE id = :id")
            ->execute([':att' => (int)($post['publish_attempts'] ?? 0) + 1, ':err' => json_encode(['crash' => mb_substr($e->getMessage(), 0, 300) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']), ':id' => $post['id']]);
        if ((int)($post['publish_attempts'] ?? 0) + 1 >= 3) paNotifyGaveUp($pdo, $post, ['crash' => $e->getMessage()]);
    } catch (Throwable $e2) { /* nothing more we can do */ }
    $results[] = ['post_id' => $post['id'] ?? null, 'platform' => '-', 'ok' => false, 'http_code' => 0];
  }
}

paDailyDigest($pdo);   // once a day from 10:00: posts that need a person (no date / no platform / X text too long / missing platform)

header('Content-Type: application/json');
echo json_encode(['checked' => count($due), 'published' => count($results), 'results' => $results]);

// Server-side mirror of the frontend's saraLearnFromWork() — most real
// publishes happen here via cron, never through the browser, so the
// frontend hook alone would miss almost everything that actually goes live.
// Best-effort: any failure here must never break the publish loop above.
/** Sara shortens a long post into one X post (max 270 characters, same language and tone). Returns null if the AI is unavailable. */
function x_caption_summarize(PDO $pdo, array $post, string $message): ?string {
    try {
        if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_ANTHROPIC_API_KEY_HERE') return null;
        $brand = '';
        if (!empty($post['client_id'])) {
            $st = $pdo->prepare("SELECT tone, dos, donts FROM client_knowledge WHERE client_id = :c LIMIT 1"); $st->execute([':c' => $post['client_id']]);
            if ($k = $st->fetch(PDO::FETCH_ASSOC)) $brand = "Brand tone: " . ($k['tone'] ?? '') . "\nDo: " . ($k['dos'] ?? '') . "\nDon't: " . ($k['donts'] ?? '') . "\n";
        }
        $prompt = "You are Sara, the agency's senior content creator. Rewrite the post below as ONE X (Twitter) post: summarise the main message, keep the same language (Arabic stays Arabic) and the client's tone, keep the key hook / offer / call to action and any phone number or link that must stay. At most 270 characters INCLUDING hashtags; at most 2 hashtags (only if the original has hashtags); no markdown, no quotation marks; do not add facts. Return ONLY the post text.\n\n{$brand}ORIGINAL:\n{$message}";
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-5-5', 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code < 200 || $code >= 300 || !$res) return null;
        $t = ''; foreach ((json_decode($res, true)['content'] ?? []) as $b) $t .= $b['text'] ?? '';
        $t = trim(trim($t), "\"“” \n");
        if ($t === '') return null;
        if (mb_strlen($t) > 280) $t = rtrim(mb_substr($t, 0, 277)) . '…';
        return $t;
    } catch (Throwable $e) { return null; }
}

function sara_learn_from_publish(PDO $pdo, array $post, string $platform): void {
    try {
        $clientId = $post['client_id'] ?? '';
        $clientName = $post['client_name'] ?? '';
        $caption = trim($post['caption'] ?? '');
        if (!$clientId || !$caption || !defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_ANTHROPIC_API_KEY_HERE') return;

        $keysStmt = $pdo->prepare("SELECT `key` FROM client_memory WHERE client_id = :cid");
        $keysStmt->execute([':cid' => $clientId]);
        $existingKeys = implode(', ', $keysStmt->fetchAll(PDO::FETCH_COLUMN)) ?: 'none';

        $prompt = "You are Sara, a senior content creator. This post just went live on {$platform} for the client \"{$clientName}\":\n\n"
            . "Title: " . ($post['title'] ?? '') . "\n"
            . "Caption: {$caption}\n"
            . (!empty($post['hashtags']) ? "Hashtags: {$post['hashtags']}\n" : "")
            . "\nExisting memory keys for this client (do NOT duplicate these): {$existingKeys}\n\n"
            . "If this reveals any durable, reusable insight about this client's brand/content strategy, return it as JSON: "
            . "[{\"key\":\"short_snake_case_key\",\"value\":\"one clear sentence\"}]\n"
            . "Return at most 2 insights. If nothing durable was revealed, return []. ONLY the JSON array, nothing else.";

        $ch = curl_init("https://api.anthropic.com/v1/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-5-5', 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ["x-api-key: " . ANTHROPIC_API_KEY, "anthropic-version: 2023-06-01", "Content-Type: application/json"],
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300 || !$res) return;

        $data = json_decode($res, true);
        $text = '';
        foreach (($data['content'] ?? []) as $block) { $text .= $block['text'] ?? ''; }
        // Extract the array specifically rather than requiring the whole
        // response to be pure JSON — a stray sentence before/after would
        // otherwise fail json_decode and silently skip this learning pass.
        preg_match('/\[[\s\S]*\]/', $text, $m);
        $insights = json_decode($m[0] ?? $text, true);
        if (!is_array($insights)) return;

        // No unique constraint on (client_id, key) exists in the schema, so
        // dedupe the same way the frontend's upsertClientMemory does: look
        // up any existing row for this key first, UPDATE if found else INSERT.
        $now = date('Y-m-d H:i:s');
        $findStmt = $pdo->prepare("SELECT id FROM client_memory WHERE client_id = :cid AND `key` = :k LIMIT 1");
        $updStmt = $pdo->prepare("UPDATE client_memory SET value = :v, type = 'ai', source = 'sara_publish', updated_at = :now WHERE id = :id");
        $insStmt = $pdo->prepare("INSERT INTO client_memory (id, client_id, client_name, `key`, value, type, source, created_at, updated_at) VALUES (UUID(), :cid, :cname, :k, :v, 'ai', 'sara_publish', :now, :now)");
        $count = 0;
        foreach (array_slice($insights, 0, 2) as $insight) {
            if (empty($insight['key']) || empty($insight['value'])) continue;
            try {
                $findStmt->execute([':cid' => $clientId, ':k' => $insight['key']]);
                $existingId = $findStmt->fetchColumn();
                if ($existingId) {
                    $updStmt->execute([':v' => $insight['value'], ':now' => $now, ':id' => $existingId]);
                } else {
                    $insStmt->execute([':cid' => $clientId, ':cname' => $clientName, ':k' => $insight['key'], ':v' => $insight['value'], ':now' => $now]);
                }
                $count++;
            } catch (Throwable $e) { /* skip this one insight, keep going */ }
        }

        if ($count > 0) {
            $log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by, performed_at) VALUES (UUID(), :action, 'agents', :details, 'success', 'agent', :now)");
            $log->execute([
                ':action' => "Sara — Senior Content Creator: Published: " . ($post['title'] ?? ''),
                ':details' => "[agent:content_creator] Published: " . ($post['title'] ?? '') . " — learned {$count} insight(s)",
                ':now' => $now,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[auto-publish] sara_learn_from_publish failed (non-fatal): ' . $e->getMessage());
    }
}
