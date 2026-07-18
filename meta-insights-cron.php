<?php
/**
 * meta-insights-cron.php — daily cron that snapshots Page/IG/Ads metrics
 * for every active Facebook/Instagram integration, so the "Meta Insights"
 * tab and client Overview trend chart have a real day-by-day history
 * instead of just today's numbers.
 *
 * Setup on Hostinger (same pattern as brief_reminder.php):
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/meta-insights-cron.php
 *   Schedule: once daily, e.g. 0 1 * * *
 *
 * One-time historical backfill (run manually, not from cron):
 *   php meta-insights-cron.php backfill
 * Fetches the last 30 days of day-by-day time-series metrics (reach,
 * impressions, etc.) in a single Graph API call per integration and stores
 * one row per day. Lifetime-only fields (follower/fan counts) have no
 * historical API — those only ever get today's value, same as the normal
 * daily run; backfilled days for that metric are simply left blank.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$isBackfill = isset($argv[1]) && $argv[1] === 'backfill';

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 30]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}

// Keeps only the metrics Meta still serves — see meta-insights.php for the rationale
// (a single deprecated metric otherwise fails the whole /insights call with #100).
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

// Regroups a Graph API /insights response (each metric has one "values"
// array with one entry per day) into per-date metric arrays:
// {"2026-07-01": [{"name":"reach","values":[{"value":123}]}], ...}
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

// Merges by metric name within each arrKey (page_insights/ig_insights/...)
// instead of replacing the whole array — otherwise a backfill run (which
// only ever has the day-by-day reach-type metrics, never the lifetime
// follower/like counts) would silently wipe out a follower count that an
// earlier daily run had already captured for that same date.
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

$selectExisting = $pdo->prepare(
    "SELECT metrics FROM meta_insights_snapshots WHERE integration_id = :iid AND platform = :platform AND snapshot_date = :date"
);
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
        ':iid'     => $integ['id'],
        ':cid'     => $integ['client_id'] ?? null,
        ':cname'   => $integ['client_name'] ?? null,
        ':platform'=> $platform,
        ':date'    => $date,
        ':metrics' => json_encode($metrics),
    ]);
}

$v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';
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

    if ($isBackfill) {
        // One call covering the last 30 days of day-by-day time-series
        // metrics, then split into one snapshot row per day.
        $since = date('Y-m-d', strtotime('-30 days'));
        $until = $today;
        if ($platform === 'facebook') {
            $series = insights_resilient("https://graph.facebook.com/{$v}/{$page_id}/insights",
                ["page_post_engagements","page_impressions_unique","page_views_total","page_fan_adds","page_daily_follows_unique","page_impressions","page_total_actions"],
                ["period" => "day", "since" => $since, "until" => $until, "access_token" => $access_token]);
            $byDate = group_by_date($series);
        } else {
            $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';
            // "reach" and "profile_views" are true day-by-day time series metrics.
            // "accounts_engaged"/"total_interactions" changed to metric_type=total_value-only
            // metrics on Meta's side — requesting them with period=day (no metric_type) either
            // errors the whole call or silently returns nothing, which is why Engagement always
            // read 0 on the trend chart. They only support ONE aggregated total over the whole
            // since/until window, not a per-day breakdown, so we can't truly backfill 30 separate
            // days of them — the best we can do is store that one window-total against today,
            // same accepted gap as the lifetime follower/fan counts below.
            $series = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
                ["reach","profile_views"],
                ["period" => "day", "since" => $since, "until" => $until, "access_token" => $access_token]);
            $byDate = group_by_date($series);
            $engTotals = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
                ["accounts_engaged","total_interactions"],
                ["period" => "day", "metric_type" => "total_value", "since" => $since, "until" => $until, "access_token" => $access_token]);
            if ($engTotals) {
                $byDate[$today] = array_merge($byDate[$today] ?? [], array_map(function($t){
                    return ["name" => $t["name"] ?? "", "title" => $t["title"] ?? ($t["name"] ?? ""), "values" => [["value" => $t["total_value"]["value"] ?? 0]]];
                }, $engTotals));
            }
        }
        foreach ($byDate as $date => $dayMetrics) {
            $key = $platform === 'facebook' ? 'page_insights' : 'ig_insights';
            save_snapshot($upsert, $selectExisting, $integ, $platform, $date, [$key => $dayMetrics]);
            $snapped++;
        }
        continue;
    }

    // Normal daily run — today's time series point plus lifetime
    // follower/like counts (no historical API for those, only "now").
    $metrics = [];
    if ($platform === 'facebook') {
        $series = insights_resilient("https://graph.facebook.com/{$v}/{$page_id}/insights",
            ["page_post_engagements","page_impressions_unique","page_views_total","page_fan_adds","page_daily_follows_unique","page_fans","page_impressions","page_total_actions"],
            ["period" => "day", "access_token" => $access_token]);
        [$pc, $presp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}", [
            "fields" => "followers_count,fan_count", "access_token" => $access_token,
        ]);
        $pageStats = [];
        if ($pc === 200) {
            if (isset($presp["followers_count"])) $pageStats[] = ["name"=>"followers_count","title"=>"Followers","values"=>[["value"=>$presp["followers_count"]]]];
            if (isset($presp["fan_count"]))       $pageStats[] = ["name"=>"fan_count","title"=>"Page Likes","values"=>[["value"=>$presp["fan_count"]]]];
        }
        $metrics['page_insights'] = array_merge($pageStats, $series);
    } else {
        $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';
        // See the backfill branch above: accounts_engaged/total_interactions need
        // metric_type=total_value or they come back empty (always reads as 0).
        $series = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
            ["reach","profile_views"],
            ["period" => "day", "access_token" => $access_token]);
        $engTotals = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
            ["accounts_engaged","total_interactions"],
            ["period" => "day", "metric_type" => "total_value", "access_token" => $access_token]);
        $engSeries = array_map(function($t){
            return ["name" => $t["name"] ?? "", "title" => $t["title"] ?? ($t["name"] ?? ""), "values" => [["value" => $t["total_value"]["value"] ?? 0]]];
        }, $engTotals);
        [$ic, $iresp] = graph_get("https://{$ig_host}/{$v}/{$page_id}", [
            "fields" => "followers_count", "access_token" => $access_token,
        ]);
        $igStats = [];
        if ($ic === 200 && isset($iresp["followers_count"])) {
            $igStats[] = ["name"=>"followers_count","title"=>"Followers","values"=>[["value"=>$iresp["followers_count"]]]];
        }
        $metrics['ig_insights'] = array_merge($igStats, $series, $engSeries);
    }

    if ($ad_account_id) {
        [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/act_{$ad_account_id}/insights", [
            "fields" => "spend,impressions,clicks,ctr,cpc,cpm,reach,actions",
            "date_preset" => "yesterday", "access_token" => $access_token,
        ]);
        $metrics['ads_insights'] = $code === 200 ? ($resp['data'] ?? []) : null;
    }

    save_snapshot($upsert, $selectExisting, $integ, $platform, $today, $metrics);
    $snapped++;
}

header('Content-Type: application/json');
echo json_encode(['mode' => $isBackfill ? 'backfill' : 'daily', 'checked' => count($integrations), 'snapshotted' => $snapped]);
