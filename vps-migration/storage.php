<?php
// ================================================================
// SocialFlow — File storage, replaces Supabase Storage.
// Mirrors the 2 calls app.jsx's uploadToStorage() makes:
//   POST {SB_STORAGE_URL}/object/{bucket}/{path}   -> upload, body = raw file bytes
//   GET  {SB_STORAGE_URL}/object/public/{bucket}/{path} -> public read (served by nginx/Apache directly, see below)
// ================================================================

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$bucket = $_GET['bucket'] ?? '';
$path = $_GET['path'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $bucket) || $path === '' || strpos($path, '..') !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid bucket or path']);
    exit;
}

$dir = STORAGE_ROOT . '/' . $bucket . '/' . dirname($path);
if (!is_dir($dir)) mkdir($dir, 0755, true);

$dest = STORAGE_ROOT . '/' . $bucket . '/' . $path;
$body = file_get_contents('php://input');
if (file_put_contents($dest, $body) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write file']);
    exit;
}

echo json_encode([
    'Key' => $bucket . '/' . $path,
    'publicUrl' => STORAGE_PUBLIC_URL . '/' . $bucket . '/' . $path,
]);
