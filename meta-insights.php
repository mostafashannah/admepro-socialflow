<?php
// ================================================================
// SocialFlow — Meta (Facebook/Instagram/Ads) insights proxy.
// Given a Page/IG access token (+ optional ad account), pulls live
// Page insights, Instagram insights, and Ads insights from the Graph
// API and returns one combined JSON payload for the frontend to render
// and (optionally) feed to the AI analysis prompt.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$data         = json_decode(file_get_contents("php://input"), true);
$platform     = strtolower(trim($data["platform"]      ?? ""));
$page_id      = trim($data["page_id"]       ?? "");
$access_token = trim($data["access_token"]  ?? "");
$ad_account_id= trim($data["ad_account_id"] ?? ""); // without "act_" prefix

if (!$page_id || !$access_token) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: page_id, access_token"]);
    exit;
}

// Caller picks a date range (last 30 days / last 3 months / custom) on the frontend
// and sends it as unix-second epochs — default to the last 30 days if not given.
$until = !empty($data["until"]) ? (int)$data["until"] : time();
$since = !empty($data["since"]) ? (int)$data["since"] : ($until - 30 * 86400);

$v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';
$out = ["platform" => $platform, "fetched_at" => date('c'), "since" => $since, "until" => $until];

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}

// "Automatic" best posting time (Client Brain → Scheduling). Meta's "online_followers"
// metric returns a lifetime breakdown of {"0".."23" => follower_count} for the hour of
// day (in the Page's/account's own timezone) when the audience is most active — exactly
// what's needed to pick a real best time instead of a guess. Only available on
// graph.facebook.com (Page-linked tokens); Instagram-Login-scoped tokens
// (graph.instagram.com) don't expose this metric, so that case is reported back
// to the frontend as unavailable so it can fall back to a manual time.
if (($data["mode"] ?? "") === "best_time") {
    $ig_login_token = $platform === "instagram" && str_starts_with($access_token, 'IGAA');
    if ($ig_login_token) {
        http_response_code(200);
        echo json_encode(["ok" => false, "error" => "Automatic best-time data isn't available for Instagram accounts connected via Instagram Login. Switch to manual for this platform."]);
        exit;
    }
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
        "metric"       => "online_followers",
        "period"       => "lifetime",
        "access_token" => $access_token,
    ]);
    $breakdown = $resp["data"][0]["values"][0]["value"] ?? null;
    if ($code !== 200 || !$breakdown) {
        http_response_code(200);
        echo json_encode(["ok" => false, "error" => "No audience-activity data available yet for this account."]);
        exit;
    }
    $bestHour = array_key_first($breakdown);
    $bestCount = -1;
    foreach ($breakdown as $hour => $count) {
        if ($count > $bestCount) { $bestCount = $count; $bestHour = $hour; }
    }
    http_response_code(200);
    echo json_encode(["ok" => true, "best_time" => sprintf("%02d:00", (int)$bestHour), "online_followers" => $breakdown]);
    exit;
}

// Meta deprecates Insights metrics on a rolling basis, and a SINGLE invalid metric
// makes the whole /insights call fail with "(#100) ... must be a valid insights metric",
// blanking the entire panel. Fetch the batch first (one call when everything's valid);
// if that fails, probe each metric on its own and keep the ones Meta still serves, so
// the dashboard degrades gracefully to whatever is currently supported.
function insights_resilient($base, array $metrics, array $extraParams) {
    [$code, $resp] = graph_get($base, array_merge($extraParams, ["metric" => implode(",", $metrics)]));
    if ($code === 200 && isset($resp["data"])) return $resp["data"];
    $data = [];
    foreach ($metrics as $m) {
        [$c, $r] = graph_get($base, array_merge($extraParams, ["metric" => $m]));
        if ($c === 200 && !empty($r["data"])) {
            $data = array_merge($data, $r["data"]);
        } else {
            error_log("insights_resilient: metric '{$m}' failed for {$base} -> HTTP {$c} " . json_encode($r));
        }
    }
    return $data;
}

if ($platform === "facebook") {
    // Candidate metrics: a mix of long-standing names and the newer replacements Meta
    // is migrating to (e.g. impressions → views). insights_resilient() keeps whichever
    // are still valid for the current API version and silently drops the deprecated ones.
    $fbMetrics = [
        "page_post_engagements",
        "page_impressions_unique",
        "page_views_total",
        "page_fan_adds",
        "page_daily_follows_unique",
        "page_fans",
        "page_impressions",
        "page_total_actions",
    ];
    $series = insights_resilient("https://graph.facebook.com/{$v}/{$page_id}/insights", $fbMetrics, [
        "period"       => "day",
        "since"        => $since,
        "until"        => $until,
        "access_token" => $access_token,
    ]);

    // Total followers + Page likes are lifetime counts the /insights endpoint no longer
    // reliably serves (page_fans/page_follows reject period=day). Read them straight from
    // the Page node — always accurate — and show them first, same approach as Instagram.
    [$pc, $presp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}", [
        "fields"       => "followers_count,fan_count,name",
        "access_token" => $access_token,
    ]);
    $pageStats = [];
    if ($pc === 200) {
        if (isset($presp["followers_count"])) $pageStats[] = ["name"=>"followers_count","title"=>"Followers","values"=>[["value"=>$presp["followers_count"]]]];
        if (isset($presp["fan_count"]))       $pageStats[] = ["name"=>"fan_count","title"=>"Page Likes","values"=>[["value"=>$presp["fan_count"]]]];
    }

    $out["page_insights"] = array_merge($pageStats, $series);
}

if ($platform === "instagram") {
    // Instagram-Login API tokens ("IGAA..." prefix) are scoped to graph.instagram.com —
    // graph.facebook.com rejects them with "Cannot parse access token". Page-linked
    // Instagram tokens (old-style) still use graph.facebook.com as before.
    $ig_host = str_starts_with($access_token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';

    // The Insights "follower_count" metric only tracks day-over-day deltas since
    // Meta started recording them for this account, and can read 0 even when the
    // account has thousands of real followers — pull the actual current count from
    // the account's own profile field instead, which is always accurate.
    [$code1, $resp1] = graph_get("https://{$ig_host}/{$v}/{$page_id}", [
        "fields"       => "followers_count",
        "access_token" => $access_token,
    ]);
    // reach and the rest are additive-eligible — metric_type=total_value aggregates
    // them (de-duplicated for reach) into one true total over the 30-day window,
    // instead of a misleading single-day or summed-with-overcounting figure. Fetched
    // resiliently so one deprecated IG metric doesn't blank the whole panel.
    $igTotals = insights_resilient("https://{$ig_host}/{$v}/{$page_id}/insights",
        ["reach","profile_views","accounts_engaged","total_interactions","likes","comments","shares","saves","replies"],
        [
            "period"       => "day",
            "metric_type"  => "total_value",
            "since"        => $since,
            "until"        => $until,
            "access_token" => $access_token,
        ]);

    if ($code1 !== 200) { $out["ig_insights"] = ["error" => $resp1]; }
    else {
        $series = [[
            "name"   => "follower_count",
            "title"  => "Follower Count",
            "values" => [["value" => $resp1["followers_count"] ?? 0]],
        ]];
        $totals = $igTotals;
        // Normalize total_value metrics into the same {title,values:[{value}]} shape
        // the time-series metrics use, so the frontend doesn't need to special-case them.
        foreach ($totals as $t) {
            $series[] = [
                "name"   => $t["name"] ?? "",
                "title"  => $t["title"] ?? ($t["name"] ?? ""),
                "values" => [["value" => $t["total_value"]["value"] ?? 0]],
            ];
        }
        $out["ig_insights"] = $series;
    }

    // The metrics above are forced into metric_type=total_value (a single
    // aggregated number for the whole window) so they can't be charted. Reach
    // is the one metric Meta still serves as a real day-by-day time series —
    // fetch it again without metric_type so the frontend has something to graph.
    [$code3, $resp3] = graph_get("https://{$ig_host}/{$v}/{$page_id}/insights", [
        "metric"       => "reach",
        "period"       => "day",
        "since"        => $since,
        "until"        => $until,
        "access_token" => $access_token,
    ]);
    $out["ig_insights_daily"] = $code3 === 200 ? ($resp3["data"] ?? []) : [];
}

if ($ad_account_id) {
    // Ads Insights uses date strings (YYYY-MM-DD) inside a time_range object, not
    // unix-second since/until like the other two endpoints.
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/act_{$ad_account_id}/insights", [
        "fields"       => "spend,impressions,clicks,ctr,cpc,cpm,reach,actions",
        "time_range"   => json_encode(["since" => date('Y-m-d', $since), "until" => date('Y-m-d', $until)]),
        "access_token" => $access_token,
    ]);
    $out["ads_insights"] = $code === 200 ? $resp["data"] ?? [] : ["error" => $resp];
}

http_response_code(200);
echo json_encode($out);
