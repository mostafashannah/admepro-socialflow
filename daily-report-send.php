<?php
// Preview / send-now for the Daily Report settings screen.
// POST {action:"preview"|"send", settings:{...current form values}} with the app's apikey header.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/pro-lib.php';
require_once __DIR__ . '/daily-report-lib.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
$key = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$key && !empty($_SERVER['HTTP_AUTHORIZATION'])) $key = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
if (!authTeamOk(false)) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing API key']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$s = array_merge(drLoadSettings($pdo), is_array($body['settings'] ?? null) ? $body['settings'] : []);
$s['sections'] = array_merge(drDefaults()['sections'], is_array($s['sections']) ? $s['sections'] : []);
try {
    if (($body['action'] ?? '') === 'send') { echo json_encode(drSend($pdo, $s)); }
    else { echo json_encode(['text' => drBuildReport($pdo, $s), 'recipients' => array_column(drRecipients($pdo, $s), 'name')]); }
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
