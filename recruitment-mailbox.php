<?php
// ================================================================
// SocialFlow — Recruitment mailbox viewer (DB-backed, IMAP-synced)
//
// Messages live in recruitment_mailbox_messages, not just fetched live
// from IMAP each request — this endpoint syncs new mail in from IMAP
// (self-throttled, at most once every 5 minutes, or immediately on
// ?force_sync=1 from the Refresh button) and prunes anything older than
// the configured retention period, then always answers from the
// database, which is fast regardless of mailbox size. Read-only from the
// client's perspective — sending replies/new emails goes through
// careers-mail.php instead.
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// A large mailbox's IMAP fetch (even with attachment content skipped —
// full message structure/headers/text bodies for a batch of messages
// still adds up) can exceed the shared hosting default of 128M; bump it
// for this script specifically rather than raising it site-wide.
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

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
$retentionDays = !empty($emailSettings['mailbox_retention_days']) ? (int) $emailSettings['mailbox_retention_days'] : 30;
if (!in_array($retentionDays, [30, 90, 180, 360], true)) $retentionDays = 30;

// ── Prune anything past retention (always, cheap, every request) ──
$pdo->prepare("DELETE FROM recruitment_mailbox_messages WHERE message_date < :cutoff")
    ->execute([':cutoff' => (new DateTime())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s')]);

// ── Self-throttled IMAP sync ──
$forceSync = isset($_GET['force_sync']) && $_GET['force_sync'] === '1';
$lastRunFile = __DIR__ . '/recruitment-mailbox-sync.lastrun';
$lastRun = file_exists($lastRunFile) ? (int) file_get_contents($lastRunFile) : 0;
$syncDue = $forceSync || (time() - $lastRun >= 300); // at most once every 5 minutes unless forced

$syncError = null;
if ($syncDue && class_exists('Webklex\\PHPIMAP\\ClientManager')) {
    file_put_contents($lastRunFile, (string) time());
    $syncError = syncMailboxFromImap($pdo, $emailSettings, $retentionDays);
}

function threadKey($subject) {
    $s = trim((string) $subject);
    while (preg_match('/^\s*(re|fwd?)\s*:\s*/i', $s)) {
        $s = preg_replace('/^\s*(re|fwd?)\s*:\s*/i', '', $s);
    }
    $s = preg_replace('/\s+/', ' ', trim($s));
    return $s === '' ? '(no subject)' : strtolower($s);
}

function syncMailboxFromImap(PDO $pdo, array $emailSettings, int $retentionDays) {
    $imapHost = !empty($emailSettings['imap_host']) ? $emailSettings['imap_host'] : (defined('RECRUITMENT_IMAP_HOST') ? RECRUITMENT_IMAP_HOST : '');
    $imapPort = !empty($emailSettings['imap_port']) ? (int) $emailSettings['imap_port'] : (defined('RECRUITMENT_IMAP_PORT') ? RECRUITMENT_IMAP_PORT : 993);
    $imapEmail = !empty($emailSettings['imap_email']) ? $emailSettings['imap_email'] : (defined('RECRUITMENT_IMAP_EMAIL') ? RECRUITMENT_IMAP_EMAIL : '');
    $imapPassword = !empty($emailSettings['imap_password']) ? $emailSettings['imap_password'] : (defined('RECRUITMENT_IMAP_PASSWORD') ? RECRUITMENT_IMAP_PASSWORD : '');
    if (!$imapHost || !$imapEmail || !$imapPassword) return 'IMAP not configured';

    $cm = new \Webklex\PHPIMAP\ClientManager();
    $client = $cm->make([
        'host' => $imapHost, 'port' => $imapPort, 'encryption' => 'ssl', 'validate_cert' => true,
        'username' => $imapEmail, 'password' => $imapPassword, 'protocol' => 'imap', 'timeout' => 30,
        // Only headers/text body — attachment bytes are never downloaded here.
        'options' => ['fetch_attachment' => false],
    ]);
    try { $client->connect(); } catch (Throwable $e) { return 'IMAP connection failed: ' . $e->getMessage(); }

    // How far back to pull NEW messages from IMAP — matches the retention
    // setting (capped) so raising retention also backfills further, not
    // just a fixed short window that could leave older mail never captured.
    $fetchDays = min($retentionDays, 360);

    $upsert = $pdo->prepare(
        "INSERT INTO recruitment_mailbox_messages (box, message_id, thread_key, subject, from_email, from_name, to_emails, message_date, body, has_attachments)
         VALUES (:box, :message_id, :thread_key, :subject, :from_email, :from_name, :to_emails, :message_date, :body, :has_attachments)
         ON DUPLICATE KEY UPDATE subject=VALUES(subject), body=VALUES(body), has_attachments=VALUES(has_attachments)"
    );

    foreach (['inbox', 'sent'] as $box) {
        $folder = null;
        if ($box === 'inbox') {
            $folder = $client->getFolder('INBOX');
        } else {
            foreach (['Sent', 'INBOX.Sent', 'Sent Items', 'INBOX/Sent', 'Sent Messages'] as $name) {
                try { $f = $client->getFolder($name); if ($f) { $folder = $f; break; } } catch (Throwable $e) { continue; }
            }
        }
        if (!$folder) continue;

        try {
            $messages = $folder->messages()->since((new DateTime())->modify("-{$fetchDays} days"))->limit(200)->setFetchOrderDesc()->get();
        } catch (Throwable $e) { continue; }

        foreach ($messages as $message) {
            try {
                $messageId = (string) $message->getMessageId();
                if ($messageId === '') $messageId = 'uid-' . $message->getUid() . '@no-message-id';
                $fromList = $message->getFrom();
                $from = $fromList && $fromList->count() ? $fromList[0] : null;
                $toList = $message->getTo();
                $to = [];
                if ($toList) { foreach ($toList as $t) { $to[] = trim((string) $t->mail); } }
                $bodyText = trim((string) $message->getTextBody());
                $bodyText = preg_replace('/\n{3,}/', "\n\n", $bodyText);
                $subject = (string) $message->getSubject();
                $msgDate = $message->getDate() ? $message->getDate()->toDate() : new DateTime();

                $upsert->execute([
                    ':box' => $box, ':message_id' => $messageId, ':thread_key' => threadKey($subject),
                    ':subject' => $subject, ':from_email' => $from ? trim((string) $from->mail) : '',
                    ':from_name' => $from && !empty($from->personal) ? trim((string) $from->personal) : '',
                    ':to_emails' => json_encode($to), ':message_date' => $msgDate->format('Y-m-d H:i:s'),
                    ':body' => mb_substr($bodyText, 0, 8000), ':has_attachments' => $message->getAttachments()->count() > 0 ? 1 : 0,
                ]);
            } catch (Throwable $e) { continue; }
        }
    }
    return null;
}

// A sent message is "system" (automated) if its subject matches one of
// the configured auto-send templates (confirmation/completion emails) —
// anything else in Sent is a real reply/new email a person typed, sent
// via the Inbox tab's Reply/New Email. Computed at read time (not
// stored) so changing the subject text in Email Settings immediately
// reclassifies existing history too, no backfill needed.
$autoSubjects = array_filter(array_map('strtolower', array_map('trim', [
    $emailSettings['confirmation_subject'] ?? 'Thanks for applying to Admepro!',
    $emailSettings['completion_subject'] ?? 'Please complete your Admepro application',
])));

$stmt = $pdo->query("SELECT * FROM recruitment_mailbox_messages ORDER BY message_date DESC LIMIT 1000");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$messages = array_map(function ($r) use ($autoSubjects) {
    $isSystem = $r['box'] === 'sent' && in_array(threadKey($r['subject']), $autoSubjects, true);
    return [
        'uid' => $r['id'],
        'box' => $r['box'],
        'message_id' => $r['message_id'],
        'thread_key' => $r['thread_key'],
        'subject' => $r['subject'],
        'from_email' => $r['from_email'],
        'from_name' => $r['from_name'],
        'to' => json_decode($r['to_emails'] ?: '[]', true) ?: [],
        'date' => $r['message_date'] ? str_replace(' ', 'T', $r['message_date']) . 'Z' : null,
        'body' => $r['body'],
        'snippet' => mb_substr(preg_replace('/\s+/', ' ', (string) $r['body']), 0, 160),
        'has_attachments' => (bool) $r['has_attachments'],
        'is_system' => $isSystem,
    ];
}, $rows);

echo json_encode(['ok' => true, 'count' => count($messages), 'synced' => $syncDue, 'sync_error' => $syncError, 'retention_days' => $retentionDays, 'messages' => $messages]);
