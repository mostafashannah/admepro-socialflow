<?php
/**
 * post-insights-cron.php — refreshes per-post engagement (likes/comments/
 * shares/reach) for recently published posts, so a "Best Performing Posts"
 * ranking can be shown to staff and clients.
 *
 * Setup on Hostinger (same pattern as meta-insights-cron.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/post-insights-cron.php
 *   Schedule: once daily, e.g. 0 2 * * *
 *
 * Only looks at posts published in the last 30 days (older posts' engagement
 * has usually settled, and this keeps the per-run API call count small).
 *
 * Loops over every platform a post was actually published to (platforms
 * JSON array), not just the legacy single `platform` column — see
 * post-insights-lib.php's refresh_post_insights_all_platforms(), shared
 * with post-insights-fetch.php (the "Refresh Now" button), for why.
 */

// CLI-only — this script performs real writes and has no authentication of
// its own, so it must never be reachable over plain HTTP (this file sits in
// the public web root alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';
require_once __DIR__ . '/post-insights-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$since = date('Y-m-d H:i:s', strtotime('-30 days'));

// external_post_id IS NOT NULL used to be the eligibility gate, but that's
// only ever the legacy single-platform id — a post with platform_post_ids
// populated but external_post_id empty (shouldn't normally happen, but
// don't silently exclude it if it does) is still eligible as long as it has
// SOME platform id recorded somewhere.
$posts = $pdo->prepare(
    "SELECT id, client_id, platform, platforms, post_type, external_post_id, platform_post_ids, insights_by_platform FROM posts
     WHERE stage = 'published'
       AND (published_at IS NULL OR published_at >= :since)
       AND ((external_post_id IS NOT NULL AND external_post_id <> '') OR (platform_post_ids IS NOT NULL AND platform_post_ids <> '{}'))"
);
$posts->execute([':since' => $since]);
$rows = $posts->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $post) {
    $result = refresh_post_insights_all_platforms($pdo, $post);
    if ($result['ok']) $updated++;
}

echo "Post insights updated: {$updated} of " . count($rows) . " eligible posts\n";
