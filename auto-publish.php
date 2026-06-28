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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';
require_once __DIR__ . '/linkedin-lib.php';

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
    if (!in_array($platform, ['facebook', 'instagram', 'linkedin'], true)) continue;

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
    if (!$page_id || !$access_token) continue;

    $design_urls   = json_decode($post['design_urls'] ?? '[]', true) ?: [];
    $design_assets = json_decode($post['design_assets'] ?? '[]', true) ?: [];
    $message       = trim(($post['caption'] ?? '') . "\n\n" . ($post['hashtags'] ?? ''));
    $image_url     = $design_urls[0] ?? ($design_assets[0]['url'] ?? '');
    // Tagged kind:"story" by the Ready Content "Also post as Instagram Story" option.
    $story_image_url = '';
    if ($platform === 'instagram') {
        foreach ($design_assets as $asset) {
            if (($asset['kind'] ?? '') === 'story') { $story_image_url = $asset['url'] ?? ''; break; }
        }
    }

    if ($platform === 'linkedin') {
        [$code, $resp] = linkedin_publish($page_id, $access_token, $message, $image_url);
    } else {
        [$code, $resp] = meta_publish($platform, $page_id, $access_token, $message, $image_url, null, $story_image_url ?: null);
    }
    $ok = $code >= 200 && $code < 300;

    if ($ok) {
        $extId = $resp['id'] ?? $resp['post_id'] ?? null;
        $upd = $pdo->prepare("UPDATE posts SET stage = 'published', published_at = :now, external_post_id = :ext, publish_error = NULL WHERE id = :id");
        $upd->execute([':now' => $now->format('Y-m-d H:i:s'), ':ext' => $extId, ':id' => $post['id']]);
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
