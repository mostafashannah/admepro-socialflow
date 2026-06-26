<?php
// ================================================================
// SocialFlow — Meta (Facebook/Instagram/Ads) insights proxy.
// Given a Page/IG access token (+ optional ad account), pulls live
// Page insights, Instagram insights, and Ads insights from the Graph
// API and returns one combined JSON payload for the frontend to render
// and (optionally) feed to the AI analysis prompt.
// ================================================================

require_once __DIR__ . '/config.php';
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

$v = "v19.0";
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

if ($platform === "facebook") {
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
        "metric"       => "page_impressions,page_engaged_users,page_fans,page_post_engagements,page_views_total",
        "period"       => "day",
        "since"        => $since,
        "until"        => $until,
        "access_token" => $access_token,
    ]);
    $out["page_insights"] = $code === 200 ? $resp["data"] ?? [] : ["error" => $resp];
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
    // instead of a misleading single-day or summed-with-overcounting figure.
    [$code2, $resp2] = graph_get("https://{$ig_host}/{$v}/{$page_id}/insights", [
        "metric"       => "reach,profile_views,accounts_engaged,total_interactions,likes,comments,shares,saves,replies",
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
        $totals = $code2 === 200 ? ($resp2["data"] ?? []) : [];
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
