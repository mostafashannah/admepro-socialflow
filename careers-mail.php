<?php
// ================================================================
// SocialFlow — Careers email proxy (SMTP via the recruitment mailbox)
//
// Same request shape as mail.php, but sends through the actual
// recruitment inbox (s.eleseely@admepro.com by default) via SMTP instead
// of Resend/noreply@admepro.com — used for emails triggered directly from
// the public /careers apply form, so sent + received mail live in one
// real mailbox, same as the recruitment cron's confirmation/completion
// emails already do.
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
if (!authResolve()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'Not signed in']); exit; } // needs a login session (or the app key until stage 3)
// Guest passes (public link pages): only to the candidate on their own application, 3 emails per pass.
if (($__a = authResolve()) && $__a['kind'] === 'session' && ($__a['session']['user_type'] ?? '') === 'guest') {
    require_once __DIR__ . '/guest-policy.php';
    $__b = json_decode(file_get_contents('php://input'), true) ?: []; $__app = guestFetchOne(authPdo(), 'job_applications', $__a['session']['user_id']); $__to = is_array($__b['to'] ?? null) ? $__b['to'] : [$__b['to'] ?? '']; if (!$__app || count($__to) !== 1 || strcasecmp(trim($__to[0]), trim((string)$__app['candidate_email'])) !== 0 || !guestQuota(authPdo(), $__a['session'], 'mail', 3)) { http_response_code(403); echo json_encode(['error' => 'Not allowed for this link']); exit; }
}
require_once __DIR__ . '/recruitment-mail-lib.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['to'], $body['subject'], $body['html'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: to, subject, html']);
    exit;
}

$to = $body['to'];
$to = is_array($to) ? ($to[0] ?? '') : $to;
$subject = $body['subject'];
$html = $body['html'];
$fromName = $body['from_name'] ?? 'Admepro Careers';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$ok = send_recruitment_email($pdo, $to, $subject, $html, $fromName);
http_response_code($ok ? 200 : 502);
echo json_encode(['ok' => $ok]);
