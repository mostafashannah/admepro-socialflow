<?php
// SocialFlow Base44 Proxy — routes Base44 API calls server-side to avoid CORS
// Supports GET, POST, PUT, DELETE — proxies all to app.base44.com

require_once __DIR__ . '/config.php';
define('B44_API_KEY', B44_API_KEY_VAL);
define('B44_APP_ID',  B44_APP_ID_VAL);
define('B44_BASE',    "https://app.base44.com/api/apps/" . B44_APP_ID . "/entities");

// CORS headers — allow browser to call this proxy from any origin
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, api_key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Require "entity" param
$entity = $_GET['entity'] ?? '';
if (empty($entity) || !preg_match('/^\w+$/', $entity)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid entity param']);
    exit();
}

// Optional record ID for PUT/DELETE
$id     = $_GET['id'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Build upstream URL
$upstream = B44_BASE . '/' . $entity;
if ($id) $upstream .= '/' . $id;

// Forward query params for GET (limit, sort, filter)
if ($method === 'GET') {
    $qp = [];
    foreach (['limit','sort','filter'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $qp[$k] = $_GET[$k];
    }
    if ($qp) $upstream .= '?' . http_build_query($qp);
}

// Read body for POST/PUT
$body = '';
if (in_array($method, ['POST','PUT'])) {
    $body = file_get_contents('php://input');
}

// cURL to Base44
$ch = curl_init($upstream);
$curlHeaders = [
    'Content-Type: application/json',
    'api_key: ' . B44_API_KEY,
];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
if ($body) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream connection failed: ' . $curlError]);
    exit();
}

http_response_code($httpCode);
echo $response;
