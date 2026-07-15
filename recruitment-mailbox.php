<?php
// ================================================================
// SocialFlow — Recruitment mailbox viewer (read-only)
//
// Lists messages from the recruitment mailbox's Inbox and/or Sent folder
// via IMAP (webklex/php-imap, same package/credentials as
// imap-recruitment-cron.php) so staff can see the actual inbox/sent
// conversation, grouped into threads, without logging into webmail
// separately. Read-only — never writes, moves, or deletes anything.
// Sending replies/new emails goes through careers-mail.php instead.
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

if (!class_exists('Webklex\\PHPIMAP\\ClientManager')) {
    http_response_code(500);
    echo json_encode(['error' => 'webklex/php-imap not installed']);
    exit;
}
use Webklex\PHPIMAP\ClientManager;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$emailSettings = [];
$row = $pdo->query("SELECT recruitment_email_settings FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row['recruitment_email_settings'])) {
    $decoded = json_decode($row['recruitment_email_settings'], true);
    if (is_array($decoded)) $emailSettings = $decoded;
}
$imapHost = !empty($emailSettings['imap_host']) ? $emailSettings['imap_host'] : (defined('RECRUITMENT_IMAP_HOST') ? RECRUITMENT_IMAP_HOST : '');
$imapPort = !empty($emailSettings['imap_port']) ? (int) $emailSettings['imap_port'] : (defined('RECRUITMENT_IMAP_PORT') ? RECRUITMENT_IMAP_PORT : 993);
$imapEmail = !empty($emailSettings['imap_email']) ? $emailSettings['imap_email'] : (defined('RECRUITMENT_IMAP_EMAIL') ? RECRUITMENT_IMAP_EMAIL : '');
$imapPassword = !empty($emailSettings['imap_password']) ? $emailSettings['imap_password'] : (defined('RECRUITMENT_IMAP_PASSWORD') ? RECRUITMENT_IMAP_PASSWORD : '');

if (!$imapHost || !$imapEmail || !$imapPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'IMAP not configured — set it up in Recruitment > Email Settings']);
    exit;
}

$cm = new ClientManager();
$client = $cm->make([
    'host'          => $imapHost,
    'port'          => $imapPort,
    'encryption'    => 'ssl',
    'validate_cert' => true,
    'username'      => $imapEmail,
    'password'      => $imapPassword,
    'protocol'      => 'imap',
    'timeout'       => 30,
]);

try {
    $client->connect();
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => 'IMAP connection failed: ' . $e->getMessage()]);
    exit;
}

// Thread key: the subject with any number of leading Re:/Fwd: prefixes
// stripped and whitespace normalized — good enough to group a real
// back-and-forth conversation without needing to walk Message-ID/
// References header chains (which some senders don't set consistently).
function threadKey($subject) {
    $s = trim((string) $subject);
    $s = preg_replace('/^\s*(re|fwd?)\s*:\s*/i', '', $s);
    while (preg_match('/^\s*(re|fwd?)\s*:\s*/i', $s)) {
        $s = preg_replace('/^\s*(re|fwd?)\s*:\s*/i', '', $s);
    }
    $s = preg_replace('/\s+/', ' ', trim($s));
    return $s === '' ? '(no subject)' : strtolower($s);
}

function fetchBox($client, $box, $limit) {
    $folder = null;
    if ($box === 'inbox') {
        $folder = $client->getFolder('INBOX');
    } else {
        foreach (['Sent', 'INBOX.Sent', 'Sent Items', 'INBOX/Sent', 'Sent Messages'] as $name) {
            try {
                $f = $client->getFolder($name);
                if ($f) { $folder = $f; break; }
            } catch (Throwable $e) { continue; }
        }
    }
    if (!$folder) return [];

    try {
        $messages = $folder->messages()->all()->limit($limit)->setFetchOrderDesc()->get();
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($messages as $message) {
        try {
            $fromList = $message->getFrom();
            $from = $fromList && $fromList->count() ? $fromList[0] : null;
            $toList = $message->getTo();
            $to = [];
            if ($toList) { foreach ($toList as $t) { $to[] = trim((string) $t->mail); } }
            $bodyText = trim((string) $message->getTextBody());
            $bodyText = preg_replace('/\n{3,}/', "\n\n", $bodyText);
            $subject = (string) $message->getSubject();
            $out[] = [
                'uid' => $message->getUid(),
                'box' => $box,
                'message_id' => (string) $message->getMessageId(),
                'thread_key' => threadKey($subject),
                'subject' => $subject,
                'from_email' => $from ? trim((string) $from->mail) : '',
                'from_name' => $from && !empty($from->personal) ? trim((string) $from->personal) : '',
                'to' => $to,
                'date' => $message->getDate() ? $message->getDate()->toDate()->format(DATE_ATOM) : null,
                'body' => mb_substr($bodyText, 0, 4000),
                'snippet' => mb_substr(preg_replace('/\s+/', ' ', $bodyText), 0, 160),
                'has_attachments' => $message->getAttachments()->count() > 0,
            ];
        } catch (Throwable $e) { continue; }
    }
    return $out;
}

$box = $_GET['box'] ?? 'all';
$limit = min(max((int) ($_GET['limit'] ?? 60), 1), 200);

$messages = [];
if ($box === 'inbox' || $box === 'all') $messages = array_merge($messages, fetchBox($client, 'inbox', $limit));
if ($box === 'sent' || $box === 'all') $messages = array_merge($messages, fetchBox($client, 'sent', $limit));

usort($messages, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

echo json_encode(['ok' => true, 'box' => $box, 'count' => count($messages), 'messages' => $messages]);
