<?php
/**
 * tiktok-refresh-cron.php — keeps connected TikTok integrations' access
 * tokens alive. TikTok access tokens expire in ~24h (vs Meta/LinkedIn's
 * long-lived tokens), so unlike those platforms this can't be a "connect
 * once and forget it" integration — without this cron, any TikTok
 * integration would silently stop working a day after connecting.
 *
 * Setup on Hostinger (same pattern as meta-insights-cron.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/tiktok-refresh-cron.php
 *   Schedule: hourly, e.g. 0 * * * *
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$rows = $pdo->query("SELECT id, credentials FROM integrations WHERE app_key = 'tiktok' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

$refreshed = 0;
$failed = 0;
foreach ($rows as $row) {
    $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
    if (empty($creds['refresh_token'])) continue;

    // Refresh proactively once under 6h of life left, rather than waiting
    // until the token is fully expired — a scheduled post or insights
    // fetch landing in that gap would otherwise fail for no visible reason.
    $expiresAt = $creds['expires_at'] ?? null;
    if ($expiresAt && strtotime($expiresAt) > time() + 21600) continue;

    $fresh = tiktok_refresh_token($creds['refresh_token']);
    if (!$fresh) { $failed++; continue; }

    $newCreds = array_merge($creds, $fresh);
    $upd = $pdo->prepare("UPDATE integrations SET credentials = :c WHERE id = :id");
    $upd->execute([':c' => json_encode($newCreds), ':id' => $row['id']]);
    $refreshed++;
}

echo "TikTok tokens refreshed: {$refreshed}, failed: {$failed}, checked: " . count($rows) . "\n";
