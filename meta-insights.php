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

$v = "v19.0";
$out = ["platform" => $platform, "fetched_at" => date('c')];

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
        "date_preset"  => "last_30d",
        "access_token" => $access_token,
    ]);
    $out["page_insights"] = $code === 200 ? $resp["data"] ?? [] : ["error" => $resp];
}

if ($platform === "instagram") {
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
        "metric"       => "reach,impressions,profile_views,follower_count",
        "period"       => "day",
        "access_token" => $access_token,
    ]);
    $out["ig_insights"] = $code === 200 ? $resp["data"] ?? [] : ["error" => $resp];
}

if ($ad_account_id) {
    [$code, $resp] = graph_get("https://graph.facebook.com/{$v}/act_{$ad_account_id}/insights", [
        "fields"       => "spend,impressions,clicks,ctr,cpc,cpm,reach,actions",
        "date_preset"  => "last_30d",
        "access_token" => $access_token,
    ]);
    $out["ads_insights"] = $code === 200 ? $resp["data"] ?? [] : ["error" => $resp];
}

http_response_code(200);
echo json_encode($out);
