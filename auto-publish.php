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

if (!defined('AUTO_PUBLISH_ENABLED') || !AUTO_PUBLISH_ENABLED) {
    echo json_encode(['skipped' => true, 'reason' => 'AUTO_PUBLISH_ENABLED is not true in config.php']);
    exit;
}

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$now = new DateTime();
$due = $pdo->query("SELECT * FROM posts WHERE stage = 'scheduled'")->fetchAll(PDO::FETCH_ASSOC);

$results = [];

foreach ($due as $post) {
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

    $platform = strtolower(trim($post['platform'] ?? ''));
    if (!in_array($platform, ['facebook', 'instagram', 'linkedin', 'tiktok'], true)) continue;

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
    // Use the LAST file added, not the first — matches publishPost() in app.jsx:
    // when a task has multiple attachments (revisions/replacements), the most
    // recently added one is the intended final version to actually publish.
    $image_url     = end($design_urls) ?: ($design_assets ? (end($design_assets)['url'] ?? '') : '');
    $cover_url     = $post['carousel_cover'] ?? '';
    // Tagged kind:"story" by the Ready Content "Also post as Instagram Story" option.
    $story_image_url = '';
    if ($platform === 'instagram') {
        foreach ($design_assets as $asset) {
            if (($asset['kind'] ?? '') === 'story') { $story_image_url = $asset['url'] ?? ''; break; }
        }
    }

    if ($platform === 'linkedin') {
        [$code, $resp] = linkedin_publish($page_id, $access_token, $message, $image_url);
    } elseif ($platform === 'tiktok') {
        if (!$image_url) {
            $upd = $pdo->prepare("UPDATE posts SET publish_attempts = :att, publish_error = :err WHERE id = :id");
            $upd->execute([':att' => $attempts + 1, ':err' => 'TikTok requires a video file attached to this post', ':id' => $post['id']]);
            continue;
        }
        [$code, $resp] = tiktok_publish_video($access_token, $image_url, $message);
    } else {
        [$code, $resp] = meta_publish($platform, $page_id, $access_token, $message, $image_url, null, $story_image_url ?: null, $post['post_type'] ?? null, $cover_url ?: null);
    }

    // Reels return 202 with a container_id; poll synchronously here since the
    // cron job is not constrained by nginx timeouts.
    if ($code === 202 && ($resp['status'] ?? '') === 'processing') {
        $container_id = $resp['container_id'] ?? '';
        $ig_user_id   = $resp['ig_user_id']   ?? $page_id;
        $published = false;
        for ($pi = 0; $pi < 30; $pi++) {
            sleep(4);
            [$code, $resp] = ig_poll_and_publish(META_GRAPH_VERSION, $ig_user_id, $access_token, $container_id);
            if ($code === 200) { $published = true; break; }
            if ($code !== 202) break;
        }
        if (!$published && $code !== 200) {
            $code = 502;
            $resp = ['error' => 'Reel processing timed out or failed during scheduled publish'];
        }
    }

    $ok = $code >= 200 && $code < 300;

    if ($ok) {
        // TikTok's publish_id ($resp['id']) can't be looked up by the
        // insights API — video_id (only present once TikTok finishes
        // processing) is what post-insights-cron.php needs stored instead.
        $extId = $resp['video_id'] ?? $resp['id'] ?? $resp['post_id'] ?? null;
        $upd = $pdo->prepare("UPDATE posts SET stage = 'published', published_at = :now, external_post_id = :ext, publish_error = NULL WHERE id = :id");
        $upd->execute([':now' => $now->format('Y-m-d H:i:s'), ':ext' => $extId, ':id' => $post['id']]);
        sara_learn_from_publish($pdo, $post, $platform);
    } else {
        $upd = $pdo->prepare("UPDATE posts SET publish_attempts = :att, publish_error = :err WHERE id = :id");
        $upd->execute([':att' => $attempts + 1, ':err' => json_encode($resp), ':id' => $post['id']]);
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
         run_count = run_count + 1, error_count = error_count + :errinc WHERE id = :iid"
    );
    $runUpd->execute([
        ':now'    => $now->format('Y-m-d H:i:s'),
        ':status' => $ok ? 'success' : 'error',
        ':msg'    => $ok ? "Published post {$post['id']}" : "Failed to publish post {$post['id']}",
        ':errinc' => $ok ? 0 : 1,
        ':iid'    => $integration['id'],
    ]);

    $results[] = ['post_id' => $post['id'], 'platform' => $platform, 'ok' => $ok, 'http_code' => $code];
}

header('Content-Type: application/json');
echo json_encode(['checked' => count($due), 'published' => count($results), 'results' => $results]);

// Server-side mirror of the frontend's saraLearnFromWork() — most real
// publishes happen here via cron, never through the browser, so the
// frontend hook alone would miss almost everything that actually goes live.
// Best-effort: any failure here must never break the publish loop above.
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
            CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
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
