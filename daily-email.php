<?php
/**
 * daily-email.php — admin-only: preview / test-send / send-to-all for the Daily
 * Performance Summary, built by the same code as the nightly email.
 *   GET  ?action=preview&email=x   → HTML
 *   POST ?action=send   {email}    → {ok}
 *   POST ?action=send_all          → {sent,total}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/daily-email-lib.php';
$a = authResolve();
$s = $a['session'] ?? null;
if (!$s || $s['user_type'] !== 'team' || $s['role'] !== 'admin') { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'Admins only']); exit; }
$pdo = authPdo();
$es = $pdo->query("SELECT * FROM email_settings WHERE setting_key = 'daily_performance_email' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$in = json_decode(file_get_contents('php://input'), true) ?: [];
foreach (['include_metrics','include_working_hours','include_completed_tasks','include_pending_tasks','include_overdue_tasks','include_motivation','subject','custom_footer','sender_name'] as $k) {
    if (array_key_exists($k, $in)) $es[$k] = $in[$k]; // unsaved toggles from the settings screen
}
$tz = new DateTimeZone(($es['timezone'] ?? '') ?: 'Africa/Cairo');
$D = de_load($pdo, $tz);
$find = function ($email) use ($D) { foreach ($D['team'] as $m) if (strcasecmp($m['email'], (string)$email) === 0) return $m; return null; };
$action = $_GET['action'] ?? '';
if ($action === 'preview') {
    $m = $find($_GET['email'] ?? '');
    header('Content-Type: text/html; charset=utf-8');
    echo $m ? de_html_for($es, $m, $D, de_motivation()) : '<p style="font-family:sans-serif;padding:20px">Pick a team member.</p>';
    exit;
}
header('Content-Type: application/json');
$from = ($es['sender_name'] ?? '') ?: 'SocialFlow';
if ($action === 'send') {
    $m = $find($in['email'] ?? '');
    if (!$m) { echo json_encode(['ok' => false, 'error' => 'Unknown member']); exit; }
    [$ok] = de_send($m['email'], de_subject($es, $tz), de_html_for($es, $m, $D, de_motivation()), $from);
    echo json_encode(['ok' => $ok]); exit;
}
if ($action === 'send_all') {
    $sent = 0; $motiv = de_motivation(); $subj = de_subject($es, $tz);
    foreach ($D['team'] as $m) { [$ok] = de_send($m['email'], $subj, de_html_for($es, $m, $D, $motiv), $from); if ($ok) $sent++; }
    echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'total' => count($D['team'])]); exit;
}
http_response_code(400); echo json_encode(['error' => 'Unknown action']);
