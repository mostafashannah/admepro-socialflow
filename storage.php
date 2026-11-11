<?php
// ================================================================
// SocialFlow — File storage, replaces Supabase Storage.
// Mirrors the 2 calls app.jsx's uploadToStorage() makes:
//   POST {SB_STORAGE_URL}/object/{bucket}/{path}   -> upload, body = raw file bytes
//   GET  {SB_STORAGE_URL}/object/public/{bucket}/{path} -> public read (served by nginx/Apache directly, see below)
// ================================================================

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization, X-SF-Session');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Accepts a real login session (X-SF-Session, see auth.php) or — for now —
// the legacy shared key. $AUTH says which; stage 1 grants both the same access.
require_once __DIR__ . '/auth-lib.php';
$AUTH = authResolve();
if (!$AUTH) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

$dest = STORAGE_ROOT . '/' . $bucket . '/' . $path;

// Never store files a browser/server could run: a .php/.html/.js uploaded here would be served from
// this same domain (code execution / stealing logged-in sessions).
if (preg_match('/\.(php\d?|phtml|phar|pht|htaccess|htm|html|xhtml|js|mjs|shtml|cgi|pl|py|sh)$/i', $path) || basename($path)[0] === '.') {
    http_response_code(400);
    echo json_encode(['error' => 'This file type cannot be uploaded']);
    exit;
}
// Guest passes (public link pages) may only add files to their own folder, never delete.
if (($AUTH['kind'] ?? '') === 'session' && ($AUTH['session']['user_type'] ?? '') === 'guest') {
    require_once __DIR__ . '/guest-policy.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !guestUploadAllowed($AUTH['session'], $path) || preg_match('/\.svg$/i', $path)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not allowed for this link']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Not finding the file is not an error from the caller's point of view —
    // the end state (file gone) is already true, so treat it the same as a
    // successful delete instead of surfacing a 404 the UI has to special-case.
    if (is_file($dest)) unlink($dest);
    echo json_encode(['deleted' => true]);
    exit;
}

$dir = STORAGE_ROOT . '/' . $bucket . '/' . dirname($path);
if (!is_dir($dir)) mkdir($dir, 0755, true);

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
