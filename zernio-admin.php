<?php
// Small helper endpoint for the Integrations screen: lists the accounts connected in Zernio and hands out the
// hosted "connect an account" link. The API key stays on the server. Team members only.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/zernio-lib.php';
header('Content-Type: application/json');
if (!authTeamOk(false)) { http_response_code(401); echo json_encode(['error' => 'Not allowed']); exit; }
if (!zernio_enabled()) { echo json_encode(['setup' => 'missing_key']); exit; }

$action = $_GET['action'] ?? 'accounts';
if ($action === 'accounts') {
    $platform = preg_replace('/[^a-z]/', '', strtolower($_GET['platform'] ?? 'linkedin'));
    [$code, $r] = zernio_request('GET', '/accounts?platform=' . $platform . '&limit=200');
    if ($code !== 200) { http_response_code($code ?: 502); echo json_encode(['error' => $r['error'] ?? 'Zernio error']); exit; }
    $list = $r['accounts'] ?? (isset($r[0]) ? $r : []);
    echo json_encode(['accounts' => array_values(array_map(fn($a) => [
        'id' => $a['_id'] ?? '', 'platform' => $a['platform'] ?? '', 'name' => $a['displayName'] ?? ($a['username'] ?? ''),
        'username' => $a['username'] ?? '', 'needs_reconnect' => !empty($a['needsReconnection']), 'profile_id' => $a['profileId'] ?? '',
    ], $list))]);
    exit;
}
if ($action === 'connect_link') {
    $platform = preg_replace('/[^a-z]/', '', strtolower($_GET['platform'] ?? 'linkedin'));
    $profileId = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['profile_id'] ?? '');
    if (!$profileId) { // default profile
        [$pc, $pr] = zernio_request('GET', '/profiles');
        $profiles = $pr['profiles'] ?? (isset($pr[0]) ? $pr : []);
        foreach ($profiles as $p) { if (!empty($p['isDefault'])) { $profileId = $p['_id']; break; } }
        if (!$profileId && $profiles) $profileId = $profiles[0]['_id'];
    }
    if (!$profileId) { echo json_encode(['error' => 'No Zernio profile found']); exit; }
    $origin = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    [$code, $r] = zernio_request('GET', '/connect/' . $platform . '?' . http_build_query(['profileId' => $profileId, 'redirect_url' => $origin . '/?zernio_connected=1']));
    echo json_encode(['url' => $r['authUrl'] ?? null, 'error' => $r['authUrl'] ?? null ? null : ($r['error'] ?? 'Zernio gave no link')]);
    exit;
}
http_response_code(400); echo json_encode(['error' => 'Unknown action']);
