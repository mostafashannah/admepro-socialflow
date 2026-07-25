<?php
// ================================================================
// SocialFlow — one-time historical backfill for a SINGLE Facebook/
// Instagram integration, fired right after it's connected/saved from
// the app (Settings → Integrations) instead of requiring someone to
// SSH in and run `php meta-insights-cron.php backfill` manually.
//
// Pulls the same last-30-days day-by-day metrics meta-insights-cron.php's
// own backfill mode does, but scoped to one integration_id so it's fast
// enough to call synchronously right after connecting, not a batch job.
// ================================================================
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$integrationId = trim($data['integration_id'] ?? '');
if (!$integrationId) { http_response_code(400); echo json_encode(['error' => 'Missing integration_id']); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$stmt = $pdo->prepare("SELECT * FROM integrations WHERE id = :id AND app_key IN ('facebook','instagram') LIMIT 1");
$stmt->execute([':id' => $integrationId]);
$integ = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$integ) { http_response_code(404); echo json_encode(['error' => 'Integration not found']); exit; }

$creds        = json_decode($integ['credentials'] ?? '{}', true) ?: [];
$page_id      = trim($creds['page_id']       ?? '');
$access_token = trim($creds['access_token']  ?? '');
$ad_account_id= trim($creds['ad_account_id'] ?? '');
if (!$page_id || !$access_token) { http_response_code(200); echo json_encode(['ok' => false, 'error' => 'Integration has no page_id/access_token yet']); exit; }

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 30]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}
function insights_resilient($base, array $metrics, array $extraParams) {
    [$code, $resp] = graph_get($base, array_merge($extraParams, ["metric" => implode(",", $metrics)]));
    if ($code === 200 && isset($resp["data"])) return $resp["data"];
    $data = [];
    foreach ($metrics as $m) {
        [$c, $r] = graph_get($base, array_merge($extraParams, ["metric" => $m]));
        if ($c === 200 && !empty($r["data"])) $data = array_merge($data, $r["data"]);
    }
    return $data;
}
function group_by_date(array $seriesData) {
    $byDate = [];
    foreach ($seriesData as $metric) {
        foreach (($metric['values'] ?? []) as $v) {
            $date = substr($v['end_time'] ?? '', 0, 10);
            if (!$date) continue;
            $byDate[$date][] = ['name' => $metric['name'], 'title' => $metric['title'] ?? $metric['name'], 'values' => [['value' => $v['value']]]];
        }
    }
    return $byDate;
}
function merge_metrics($old, $new) {
    $out = $old;
    foreach ($new as $key => $arr) {
        if (!isset($out[$key]) || !is_array($out[$key])) { $out[$key] = $arr; continue; }
        $byName = [];
        foreach ($out[$key] as $m) { if (isset($m['name'])) $byName[$m['name']] = $m; }
        foreach ($arr as $m) { if (isset($m['name'])) $byName[$m['name']] = $m; }
        $out[$key] = array_values($byName);
    }
    return $out;
}
$selectExisting = $pdo->prepare("SELECT metrics FROM meta_insights_snapshots WHERE integration_id = :iid AND platform = :platform AND snapshot_date = :date");
$upsert = $pdo->prepare(
    "INSERT INTO meta_insights_snapshots (id, integration_id, client_id, client_name, platform, snapshot_date, metrics)
     VALUES (UUID(), :iid, :cid, :cname, :platform, :date, :metrics)
     ON DUPLICATE KEY UPDATE metrics = VALUES(metrics)"
);
function save_snapshot($upsert, $selectExisting, $integ, $platform, $date, $metrics) {
    $selectExisting->execute([':iid' => $integ['id'], ':platform' => $platform, ':date' => $date]);
    $existingRaw = $selectExisting->fetchColumn();
    if ($existingRaw) {
        $existing = json_decode($existingRaw, true) ?: [];
        $metrics = merge_metrics($existing, $metrics);
    }
    $upsert->execute([
        ':iid' => $integ['id'], ':cid' => $integ['client_id'] ?? null, ':cname' => $integ['client_name'] ?? null,
        ':platform' => $platform, ':date' => $date, ':metrics' => json_encode($metrics),
    ]);
}

$v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';
$today = date('Y-m-d');
$since = date('Y-m-d', strtotime('-30 days'));
$platform = $integ['app_key'];

if ($platform === 'facebook') {
    $series = insights_resilient("https://graph.facebook.com/{$v}/{$page_id}/insights",
        ["page_post_engagements","page_impressions_unique","page_views_total","page_fan_adds","page_daily_follows_unique","page_impressions","page_total_actions"],
        ["period" => "day", "since" => $since, "until" => $today, "access_token" => $access_token]);
    $byDate = group_by_date($series);
} else {
    $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';
    $series = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
        ["reach","profile_views"],
        ["period" => "day", "since" => $since, "until" => $today, "access_token" => $access_token]);
    $byDate = group_by_date($series);
    $engTotals = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
        ["accounts_engaged","total_interactions"],
        ["period" => "day", "metric_type" => "total_value", "since" => $since, "until" => $today, "access_token" => $access_token]);
    if ($engTotals) {
        $byDate[$today] = array_merge($byDate[$today] ?? [], array_map(function($t){
            return ["name" => $t["name"] ?? "", "title" => $t["title"] ?? ($t["name"] ?? ""), "values" => [["value" => $t["total_value"]["value"] ?? 0]]];
        }, $engTotals));
    }
}

$snapped = 0;
foreach ($byDate as $date => $dayMetrics) {
    $key = $platform === 'facebook' ? 'page_insights' : 'ig_insights';
    save_snapshot($upsert, $selectExisting, $integ, $platform, $date, [$key => $dayMetrics]);
    $snapped++;
}

if ($ad_account_id) {
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/act_{$ad_account_id}/insights", [
        "fields" => "spend,impressions,clicks,ctr,cpc,cpm,reach,actions",
        "time_range" => json_encode(["since" => $since, "until" => $today]),
        "access_token" => $access_token,
    ]);
    if ($code === 200) {
        save_snapshot($upsert, $selectExisting, $integ, $platform, $today, ['ads_insights' => $resp['data'] ?? []]);
        $snapped++;
    }
}

echo json_encode(['ok' => true, 'snapshotted_days' => $snapped]);
