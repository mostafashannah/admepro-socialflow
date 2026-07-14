<?php
// ================================================================
// SocialFlow — CORS proxy for fetching a CV file from a third-party URL
// (Google Drive share links, etc.) from the browser.
//
// The browser can't fetch drive.google.com directly for CV scoring/PDF
// conversion — Google doesn't send Access-Control-Allow-Origin headers,
// so the request is blocked by CORS with a generic "Load failed" before
// any bytes come back. A server-to-server fetch isn't subject to CORS,
// so this endpoint downloads the file here and streams it back with
// permissive CORS headers.
// ================================================================

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$url = $_GET['url'] ?? '';
if (!preg_match('#^https://#i', $url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only https:// URLs are allowed']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (SocialFlow CV fetch proxy)',
    // Guard against a huge/streaming response tying up the request.
    CURLOPT_BUFFERSIZE => 65536,
    CURLOPT_NOPROGRESS => false,
    CURLOPT_PROGRESSFUNCTION => function ($res, $expectedDown, $downloaded) {
        return $downloaded > 20 * 1024 * 1024 ? 1 : 0; // abort past 20MB
    },
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $status >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'Fetch failed: ' . ($err ?: "HTTP $status")]);
    exit;
}

header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
echo $body;
