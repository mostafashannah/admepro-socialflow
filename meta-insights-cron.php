<?php
/**
 * meta-insights-cron.php — daily cron that snapshots Page/IG/Ads metrics
 * for every active Facebook/Instagram integration, so the "Meta Insights"
 * tab has a real trend to learn from instead of just today's numbers.
 *
 * Setup on Hostinger (same pattern as brief_reminder.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/meta-insights-cron.php
 *   Schedule: once daily, e.g. 0 1 * * *
 */

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}

$v = "v19.0";
$today = date('Y-m-d');
$integrations = $pdo->query("SELECT * FROM integrations WHERE status = 'active' AND app_key IN ('facebook','instagram')")->fetchAll(PDO::FETCH_ASSOC);

$snapped = 0;

foreach ($integrations as $integ) {
    $creds        = json_decode($integ['credentials'] ?? '{}', true) ?: [];
    $page_id      = trim($creds['page_id']       ?? '');
    $access_token = trim($creds['access_token']  ?? '');
    $ad_account_id= trim($creds['ad_account_id'] ?? '');
    if (!$page_id || !$access_token) continue;

    $platform = $integ['app_key'];
    $metrics  = [];

    if ($platform === 'facebook') {
        [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
            "metric" => "page_impressions,page_engaged_users,page_fans,page_post_engagements,page_views_total",
            "period" => "day", "access_token" => $access_token,
        ]);
        $metrics['page_insights'] = $code === 200 ? ($resp['data'] ?? []) : null;
    } else {
        [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
            "metric" => "reach,impressions,profile_views,follower_count",
            "period" => "day", "access_token" => $access_token,
        ]);
        $metrics['ig_insights'] = $code === 200 ? ($resp['data'] ?? []) : null;
    }

    if ($ad_account_id) {
        [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/act_{$ad_account_id}/insights", [
            "fields" => "spend,impressions,clicks,ctr,cpc,cpm,reach,actions",
            "date_preset" => "yesterday", "access_token" => $access_token,
        ]);
        $metrics['ads_insights'] = $code === 200 ? ($resp['data'] ?? []) : null;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO meta_insights_snapshots (id, integration_id, client_id, client_name, platform, snapshot_date, metrics)
         VALUES (UUID(), :iid, :cid, :cname, :platform, :date, :metrics)"
    );
    $stmt->execute([
        ':iid'     => $integ['id'],
        ':cid'     => $integ['client_id'] ?? null,
        ':cname'   => $integ['client_name'] ?? null,
        ':platform'=> $platform,
        ':date'    => $today,
        ':metrics' => json_encode($metrics),
    ]);
    $snapped++;
}

header('Content-Type: application/json');
echo json_encode(['checked' => count($integrations), 'snapshotted' => $snapped]);
