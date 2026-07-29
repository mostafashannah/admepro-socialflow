<?php
// ================================================================
// SocialFlow — Recruitment WhatsApp notify proxy
//
// Called from the public interview-scheduling / offer-response pages
// (no login) right after a candidate submits a response, so Admin/HR get
// pinged on WhatsApp immediately instead of only finding out next time
// someone opens the Recruitment page. Reuses the same "Pro" WhatsApp
// number/sender used everywhere else (pro-lib.php's sendWhatsAppReply).
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php';

$body = json_decode(file_get_contents('php://input'), true);
$message = trim($body['message'] ?? '');
if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: message']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $stmt = $pdo->query(
        "SELECT tm.whatsapp_number, np.all_disabled, np.wa_recruitment_candidate_response
         FROM team_members tm LEFT JOIN notification_prefs np ON np.user_email = tm.email
         WHERE tm.role IN ('admin', 'hr') AND tm.status = 'active' AND tm.whatsapp_number IS NOT NULL AND tm.whatsapp_number != ''"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    foreach ($rows as $row) {
        if (!empty($row['all_disabled']) || $row['wa_recruitment_candidate_response'] === '0') continue;
        $digits = preg_replace('/\D/', '', (string)$row['whatsapp_number']);
        if (!$digits) continue;
        if (sendWhatsAppReply($digits, $message)) $sent++;
    }
    echo json_encode(['ok' => true, 'sent' => $sent]);
} catch (Throwable $e) {
    error_log('[recruitment-notify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send notifications']);
}
