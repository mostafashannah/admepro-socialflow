<?php
// ================================================================
// SocialFlow — Egypt public holidays fetch proxy
//
// Frontend runs sandboxed and can't call external APIs directly, but
// this server can. Proxies date.nager.at's free public-holidays API
// (no key required) for Egypt (EG), covering both fixed-date holidays
// and Islamic/lunar ones (Eid al-Fitr, Eid al-Adha, Islamic New Year,
// Prophet's Birthday) — dates for the current/near-future year are
// estimates until officially confirmed by moon sighting, same caveat
// as manual entry.
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

$url = "https://date.nager.at/api/v3/publicholidays/{$year}/EG";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch holidays', 'detail' => $err ?: "HTTP $httpCode"]);
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'Unexpected response from holidays provider']);
    exit;
}

$out = [];
foreach ($data as $h) {
    if (!isset($h['date'], $h['localName'])) continue;
    $out[] = ['date' => $h['date'], 'name' => $h['localName']];
}

echo json_encode(['ok' => true, 'year' => $year, 'holidays' => $out]);
