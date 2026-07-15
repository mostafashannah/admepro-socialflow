<?php
/**
 * imap-recruitment-cron.php — polls the recruitment mailbox (e.g.
 * s.eleseely@admepro.com, which hr@admepro.com forwards into) for
 * application emails and turns them into job_applications rows, same as
 * the public /careers form does:
 *   - matches the email subject against open job_openings titles
 *     (case-insensitive substring match; falls back to "Unassigned")
 *   - saves the first PDF attachment found as the CV
 *   - runs it through the same AI review Claude does for web
 *     applications (score/summary/highlights/red flags)
 *   - sends the applicant the same "Thanks for applying" confirmation
 *   - dedupes by the email's Message-ID (RECRUITMENT_IMAP_* config +
 *     migration-recruitment-email-inbox.sql required)
 *
 * Uses the webklex/php-imap Composer package instead of PHP's built-in
 * ext-imap — ext-imap's underlying c-client library has a known bug
 * where imap_open() can hang indefinitely during the TLS handshake with
 * some modern mail servers, even with imap_timeout() set. Confirmed on
 * this exact setup: a raw `openssl s_client` IMAP LOGIN against Hostinger
 * completed instantly, but PHP's imap_open() to the same server hung
 * forever — so ext-imap itself is the broken component, not the network
 * or credentials. webklex/php-imap talks IMAP over a plain PHP stream
 * socket and doesn't have this issue.
 *
 * Setup:
 *   composer require webklex/php-imap
 *
 * Crontab — run every minute; the script self-throttles to the actual
 * polling interval set in Recruitment → Email Settings ("Check For New
 * Applications Every"), skipping without connecting to IMAP at all until
 * that interval has elapsed (see imap-recruitment-cron.lastrun below):
 *   (star)/1 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/imap-recruitment-cron.php >> /var/www/socialflow/imap-recruitment-cron.log 2>&1
 * (5-field cron wildcard syntax — written out here so this docblock comment doesn't break PHP parsing)
 */

// A mailbox with several days' worth of messages (each with real CV/
// portfolio attachments actually being downloaded here, unlike the
// read-only mailbox viewer) can exceed the shared hosting default of
// 128M — confirmed via a fatal "Allowed memory size exhausted" on a
// 6-day backfill run. Bump it for this script specifically.
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recruitment-mail-lib.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run: composer require webklex/php-imap\n");
    echo json_encode(['error' => 'webklex/php-imap not installed']);
    exit(1);
}
require_once $autoload;

use Webklex\PHPIMAP\ClientManager;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function email_base($content) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 20px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
  <tr><td style="padding:32px 36px 0">
    <img src="https://admepro.com/wp-content/uploads/2024/10/adme-p2.png" alt="Admepro" style="height:28px;width:auto"/>
  </td></tr>
  <tr><td style="padding:24px 36px 36px">'.$content.'</td></tr>
</table>
</td></tr></table>
</body></html>';
}

// Careers/recruitment emails now send via SMTP through the actual
// recruitment mailbox (send_recruitment_email(), recruitment-mail-lib.php)
// instead of Resend, so sent + received mail live in the same inbox.

function log_activity($pdo, $applicationId, $action, $actor = 'System') {
    try {
        $pdo->prepare("INSERT INTO job_application_activity (id, application_id, action, actor_name) VALUES (UUID(), :aid, :action, :actor)")
            ->execute([':aid' => $applicationId, ':action' => $action, ':actor' => $actor]);
    } catch (Throwable $e) {
        // Logging must never break the actual capture/email flow.
    }
}

// Extracts plain text from a .docx file's raw bytes — a .docx is a zip
// archive with the actual text in word/document.xml. Legacy .doc (pre-2007
// binary format) can't be read this way; returns null for those/on any
// failure so the caller can fall back to tagging the CV as unreadable
// instead of scoring garbage.
function extract_docx_text($rawBytes) {
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmp, $rawBytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { unlink($tmp); return null; }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($tmp);
    if ($xml === false) return null;
    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
    $text = trim(html_entity_decode(strip_tags($xml)));
    return $text !== '' ? $text : null;
}

// Same shape/prompt as reviewApplication() in app.jsx, called server-side
// here instead of from the browser. $cvContent is a pre-built Claude
// content block — a "document" block for a PDF, or a "text" block with
// extracted text for a .docx (see extract_docx_text above).
function ai_review_cv($pdoRef, $applicationId, $cvContent, $jobTitle, $jobDescription, $jobRequirements) {
    $sys = "You are an HR screening assistant reviewing a candidate's CV (attached below, as a PDF document or as extracted text from a Word document) for the position \"".($jobTitle ?: 'this role')."\".\n"
        . "Job description: " . substr($jobDescription ?: '', 0, 1500) . "\n"
        . "Requirements: " . substr($jobRequirements ?: '', 0, 1500) . "\n\n"
        . "Read the CV and return ONLY JSON in this exact shape:\n"
        . '{"candidate_name":"","candidate_email":"","candidate_phone":"","years_experience":0,"skills":["..."],"education":"","languages":["..."],"highlights":["up to 5 concrete, specific achievements or qualifications relevant to this role"],"red_flags":["up to 3 concerns, gaps, or mismatches — empty array if none"],"links":["any LinkedIn/Behance/Canva/portfolio/personal-site URLs found in the document — empty array if none"],"score":0,"summary":"2-3 sentence overall assessment of fit for this specific role"}' . "\n"
        . '"score" is 0-100, reflecting fit for THIS role specifically (not a generic CV quality score).';

    $payload = json_encode([
        "model" => "claude-sonnet-4-6", "max_tokens" => 1500, "system" => $sys,
        "messages" => [["role" => "user", "content" => [
            $cvContent,
            ["type" => "text", "text" => "Review this CV and return the JSON described in the system prompt."],
        ]]],
    ]);
    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ["x-api-key: " . ANTHROPIC_API_KEY, "anthropic-version: 2023-06-01", "Content-Type: application/json"],
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $upd = $pdoRef->prepare("UPDATE job_applications SET ai_score=:s, ai_summary=:sum, ai_extracted=:ext, ai_review_status=:st WHERE id=:id");
    $fail = fn() => $upd->execute([':s' => null, ':sum' => null, ':ext' => null, ':st' => 'failed', ':id' => $applicationId]);

    if ($status < 200 || $status >= 300) { $fail(); return; }
    $d = json_decode($res, true);
    $raw = '';
    foreach (($d['content'] ?? []) as $block) { $raw .= $block['text'] ?? ''; }
    if (!preg_match('/\{[\s\S]*\}/', $raw, $m)) { $fail(); return; }
    $extracted = json_decode($m[0], true);
    if (!$extracted) { $fail(); return; }

    // Backfill phone/links from the CV if the email capture didn't have them.
    if (!empty($extracted['candidate_phone'])) {
        $pdoRef->prepare("UPDATE job_applications SET candidate_phone=:p WHERE id=:id AND (candidate_phone IS NULL OR candidate_phone = '')")
            ->execute([':p' => $extracted['candidate_phone'], ':id' => $applicationId]);
    }
    if (!empty($extracted['links']) && is_array($extracted['links'])) {
        $linkedinLink = null; $portfolioLink = null;
        foreach ($extracted['links'] as $link) {
            if (!$link) continue;
            if (stripos($link, 'linkedin.com') !== false) { if (!$linkedinLink) $linkedinLink = $link; }
            elseif (!$portfolioLink) { $portfolioLink = $link; }
        }
        if ($linkedinLink) {
            $pdoRef->prepare("UPDATE job_applications SET linkedin_url=:l WHERE id=:id AND (linkedin_url IS NULL OR linkedin_url = '')")
                ->execute([':l' => $linkedinLink, ':id' => $applicationId]);
        }
        if ($portfolioLink) {
            $pdoRef->prepare("UPDATE job_applications SET portfolio_url=:l WHERE id=:id AND (portfolio_url IS NULL OR portfolio_url = '')")
                ->execute([':l' => $portfolioLink, ':id' => $applicationId]);
        }
    }

    $score = isset($extracted['score']) && is_numeric($extracted['score']) ? max(0, min(100, round($extracted['score']))) : null;
    $upd->execute([
        ':s' => $score,
        ':sum' => substr($extracted['summary'] ?? '', 0, 600),
        ':ext' => json_encode($extracted),
        ':st' => 'done',
        ':id' => $applicationId,
    ]);
}

// UI-configurable overrides (Recruitment → Email Settings in app.jsx)
// take priority over the RECRUITMENT_IMAP_* constants in config.php,
// which remain the fallback when nothing's been saved from the UI yet.
$emailSettings = [];
$settingsRow = $pdo->query("SELECT recruitment_email_settings FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($settingsRow && !empty($settingsRow['recruitment_email_settings'])) {
    $decoded = json_decode($settingsRow['recruitment_email_settings'], true);
    if (is_array($decoded)) $emailSettings = $decoded;
}
$imapHost = !empty($emailSettings['imap_host']) ? $emailSettings['imap_host'] : RECRUITMENT_IMAP_HOST;
$imapPort = !empty($emailSettings['imap_port']) ? (int) $emailSettings['imap_port'] : RECRUITMENT_IMAP_PORT;
$imapEmail = !empty($emailSettings['imap_email']) ? $emailSettings['imap_email'] : RECRUITMENT_IMAP_EMAIL;
$imapPassword = !empty($emailSettings['imap_password']) ? $emailSettings['imap_password'] : RECRUITMENT_IMAP_PASSWORD;
$confirmationEnabled = !array_key_exists('confirmation_enabled', $emailSettings) || $emailSettings['confirmation_enabled'] !== false;
$confirmationFromName = !empty($emailSettings['confirmation_from_name']) ? $emailSettings['confirmation_from_name'] : 'Admepro Careers';
$confirmationSubject = !empty($emailSettings['confirmation_subject']) ? $emailSettings['confirmation_subject'] : 'Thanks for applying to Admepro!';
$confirmationMessage = !empty($emailSettings['confirmation_message']) ? $emailSettings['confirmation_message'] : "We've received your application at Admepro. Our recruitment team is reviewing it now, and we'll get back to you as soon as possible.";
$strictFiltering = !array_key_exists('strict_filtering', $emailSettings) || $emailSettings['strict_filtering'] !== false;
$completionSubject = !empty($emailSettings['completion_subject']) ? $emailSettings['completion_subject'] : 'Please complete your Admepro application';
$completionMessage = !empty($emailSettings['completion_message']) ? $emailSettings['completion_message'] : "Thanks for applying! To finish reviewing your application, could you fill in a few more details:";

// The OS crontab (see docblock) triggers this script every minute — the
// finest granularity cron supports — but the actual polling interval is
// controlled by "Check For New Applications Every" in Recruitment → Email
// Settings. Self-throttle here: skip this run entirely (no IMAP
// connection at all) until that interval has elapsed since the last run.
$pollIntervalMinutes = !empty($emailSettings['poll_interval_minutes']) ? (int) $emailSettings['poll_interval_minutes'] : 5;
$lastRunFile = __DIR__ . '/imap-recruitment-cron.lastrun';
$lastRun = file_exists($lastRunFile) ? (int) file_get_contents($lastRunFile) : 0;
// Manual one-off backfill (e.g. `?backfill_days=10`), for the rare case an
// application needs to be recaptured from an email older than the normal
// 2-day window (missed on first pass, tombstone was cleared, etc) — bypasses
// the throttle so it runs immediately regardless of poll_interval_minutes.
$backfillDays = isset($_GET['backfill_days']) ? max(1, min(90, (int) $_GET['backfill_days'])) : null;
if (!$backfillDays && time() - $lastRun < $pollIntervalMinutes * 60) {
    echo json_encode(['skipped' => true, 'reason' => 'not due yet', 'poll_interval_minutes' => $pollIntervalMinutes]);
    exit(0);
}
file_put_contents($lastRunFile, (string) time());

$cm = new ClientManager();
$client = $cm->make([
    'host'          => $imapHost,
    'port'          => $imapPort,
    'encryption'    => 'ssl',
    'validate_cert' => true,
    'username'      => $imapEmail,
    'password'      => $imapPassword,
    'protocol'      => 'imap',
    'timeout'       => 60,
]);

try {
    $client->connect();
} catch (Throwable $e) {
    echo json_encode(['error' => 'IMAP connection failed: ' . $e->getMessage()]);
    exit(1);
}

$openings = $pdo->query("SELECT id, title, description, requirements FROM job_openings WHERE status = 'open'")->fetchAll(PDO::FETCH_ASSOC);
$checkDup = $pdo->prepare("SELECT 1 FROM job_applications WHERE email_message_id = :mid");
// A tombstone of message ids whose application was manually deleted by an
// admin — deleting the job_applications row alone doesn't stop this cron
// from re-capturing the same email next run, since the UNIQUE constraint
// above only protects rows that still exist.
$checkDeleted = $pdo->prepare("SELECT 1 FROM deleted_email_applications WHERE email_message_id = :mid");
$insert = $pdo->prepare(
    "INSERT INTO job_applications (id, job_opening_id, job_title, candidate_name, candidate_email, candidate_phone, cover_letter, cv_url, portfolio_url, portfolio_attachment_url, linkedin_url, status, ai_review_status, source, email_message_id)
     VALUES (UUID(), :job_opening_id, :job_title, :candidate_name, :candidate_email, :candidate_phone, :cover_letter, :cv_url, :portfolio_url, :portfolio_attachment_url, :linkedin_url, 'new', :ai_review_status, 'email', :email_message_id)"
);
$lookup = $pdo->prepare("SELECT id FROM job_applications WHERE email_message_id = :mid");

$folder = $client->getFolder('INBOX');
// Deliberately NOT filtering by unseen() — this mailbox may also be read
// by a human (e.g. checking it via webmail), which marks messages Seen
// and would make them invisible to an unseen-only query, silently
// dropping applications that arrived but got read before this script
// ran. Instead pull everything from the last 2 days (cron runs every
// minute, so this is far more buffer than needed for anything actually
// new) and rely purely on the email_message_id UNIQUE constraint
// (checkDup below) to skip messages already turned into an application.
// A wider window risks the IMAP server dropping the connection
// mid-fetch on a large mailbox, so this is intentionally tight.
try {
    $sinceDays = $backfillDays ?: 2;
    $messages = $folder->messages()->since((new DateTime())->modify("-{$sinceDays} days"))->get();
} catch (Throwable $e) {
    echo json_encode(['error' => 'Failed to fetch messages: ' . $e->getMessage()]);
    exit(1);
}
$processed = 0;

foreach ($messages as $message) {
    try {
        $messageId = (string) $message->getMessageId();
        if ($messageId === '') { $messageId = 'uid-' . $message->getUid() . '@no-message-id'; }

        $checkDup->execute([':mid' => $messageId]);
        if ($checkDup->fetchColumn()) { $message->setFlag('Seen'); continue; }

        $checkDeleted->execute([':mid' => $messageId]);
        if ($checkDeleted->fetchColumn()) { $message->setFlag('Seen'); continue; }

        $fromList = $message->getFrom();
        $from = $fromList && $fromList->count() ? $fromList[0] : null;
        $candidateEmail = $from ? trim((string) $from->mail) : '';
        $candidateName = $from && !empty($from->personal) ? trim((string) $from->personal) : $candidateEmail;
        $subject = (string) $message->getSubject();

        if ($candidateEmail === '') { $message->setFlag('Seen'); continue; }
        // Skip our own mail — a message "from" the mailbox itself, from
        // noreply@admepro.com (the confirmation email's own sender), or
        // whose subject IS the confirmation email's subject line, is our
        // own outgoing reply landing back in this inbox — not a real
        // applicant. Checking the subject too catches the case where the
        // confirmation gets forwarded/bounced through a different sender.
        $selfSenders = [strtolower($imapEmail), 'noreply@admepro.com'];
        $isOwnConfirmation = in_array(strtolower($candidateEmail), $selfSenders, true)
            || strcasecmp(trim($subject), trim($confirmationSubject)) === 0;
        if ($isOwnConfirmation) { $message->setFlag('Seen'); continue; }

        // Match subject against open job titles (case-insensitive substring)
        $matchedOpening = null;
        foreach ($openings as $o) {
            if ($o['title'] !== '' && (stripos($subject, $o['title']) !== false || stripos($o['title'], $subject) !== false)) { $matchedOpening = $o; break; }
        }

        // Grab the first PDF attachment as the CV, and (if present) a second
        // attachment (any type) as the optional portfolio attachment.
        $cvUrl = null;
        $portfolioAttachmentUrl = null;
        $saveAttachment = function ($attachment, $subdir, $fallbackName) {
            $raw = $attachment->getContent();
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $attachment->getName() ?: $fallbackName);
            $path = 'job-applications/' . $subdir . '/' . time() . '_' . $safeName;
            $dir = STORAGE_ROOT . '/socialflow-media/' . dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents(STORAGE_ROOT . '/socialflow-media/' . $path, $raw);
            return ['url' => STORAGE_PUBLIC_URL . '/socialflow-media/' . $path, 'base64' => base64_encode($raw)];
        };
        foreach ($message->getAttachments() as $attachment) {
            $name = strtolower((string) $attachment->getName());
            $mime = strtolower((string) $attachment->getMimeType());
            $isPdf = str_ends_with($name, '.pdf') || $mime === 'application/pdf';
            $isDocx = str_ends_with($name, '.docx') || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $isDoc = !$isDocx && (str_ends_with($name, '.doc') || $mime === 'application/msword');
            if (($isPdf || $isDocx || $isDoc) && !$cvUrl) {
                $cvUrl = $saveAttachment($attachment, 'email', $isPdf ? 'cv.pdf' : ($isDocx ? 'cv.docx' : 'cv.doc'));
                $cvUrl['is_docx'] = $isDocx;
                $cvUrl['is_doc'] = $isDoc;
            } elseif (!$portfolioAttachmentUrl) {
                $portfolioAttachmentUrl = $saveAttachment($attachment, 'portfolio', 'portfolio');
            }
        }
        $bodyText = trim((string) $message->getTextBody());

        // Applications submitted through a website form (e.g. WordPress)
        // often arrive with the site's own relay address as the sender
        // (e.g. wordpress@admepro.com) and the real applicant's name/email
        // stated in the body instead (e.g. "Name: Touka <touka@gmail.com>").
        // If the header sender is on the same domain as this mailbox, trust
        // the body's email/name over the relay address.
        $mailboxDomain = strtolower(substr(strrchr($imapEmail, '@'), 1));
        $fromDomain = strtolower(substr(strrchr($candidateEmail, '@'), 1));
        if ($fromDomain !== '' && $fromDomain === $mailboxDomain && preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $bodyText, $bodyEmailMatch)) {
            $bodyEmail = $bodyEmailMatch[0];
            if (strtolower($bodyEmail) !== strtolower($candidateEmail)) {
                $candidateEmail = $bodyEmail;
                if (preg_match('/Name:\s*([^<\n\r]+)/i', $bodyText, $nameMatch)) {
                    $candidateName = trim($nameMatch[1]);
                } elseif ($candidateName === '' || strtolower($candidateName) === strtolower($from->mail ?? '')) {
                    $candidateName = $bodyEmail;
                }
            }
        }

        // Pull any links out of the body: LinkedIn goes to linkedin_url, the
        // first other link (portfolio/Behance/personal site/etc.) to
        // portfolio_url — same fields the public /careers form fills in. A
        // second non-LinkedIn link (e.g. a Google Drive CV link some
        // applicants paste instead of attaching a file) goes into
        // portfolio_attachment_url as a clickable link, same slot used for
        // an actual second file attachment.
        $linkedinUrl = null;
        $portfolioUrl = null;
        $secondLink = null;
        if (preg_match_all('/https?:\/\/[^\s<>")]+/i', $bodyText, $urlMatches)) {
            foreach ($urlMatches[0] as $url) {
                $url = rtrim($url, '.,;:!?');
                if (stripos($url, 'linkedin.com') !== false) {
                    if (!$linkedinUrl) $linkedinUrl = $url;
                } elseif (!$portfolioUrl) {
                    $portfolioUrl = $url;
                } elseif (!$secondLink && $url !== $portfolioUrl) {
                    $secondLink = $url;
                }
            }
        }
        if ($secondLink && !$portfolioAttachmentUrl) {
            $portfolioAttachmentUrl = ['url' => $secondLink];
        }

        // Best-effort phone number from the body (AI review, when it runs,
        // may still overwrite this with a more accurate one from the CV).
        // Validate the digit COUNT of each candidate match (10-14 digits
        // covers local 11-digit Egyptian mobiles and +20-prefixed
        // international ones) rather than trusting the first digit-run
        // found — guards against picking a shorter, truncated match when
        // multiple number-like sequences appear in the body.
        $candidatePhone = null;
        if (preg_match_all('/\+?\d[\d\s\-().]{7,16}\d/', $bodyText, $phoneMatches)) {
            foreach ($phoneMatches[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate);
                if (strlen($digits) >= 10 && strlen($digits) <= 14) { $candidatePhone = trim($candidate); break; }
            }
        }

        // A "Re:"/"Fwd:"/"Fw:" subject means this is a reply/forward inside
        // an existing email thread — either our own confirmation being
        // replied to, or back-and-forth conversation — never a fresh
        // application, even if it happens to have an attachment or a
        // matching subject. Skip it outright.
        if (preg_match('/^\s*(re|fwd?)\s*:/i', $subject)) {
            $message->setFlag('Seen');
            continue;
        }

        // Strict filtering (Recruitment → Email Settings): only treat a
        // message as a real application if its subject matched an open job
        // or it has a CV attachment — otherwise skip mailbox noise
        // (newsletters, our own reply confirmations, unrelated mail)
        // instead of inserting it as an "Unassigned" application.
        if ($strictFiltering && !$matchedOpening && !$cvUrl) {
            $message->setFlag('Seen');
            continue;
        }

        // Same guard as the public form: the same email applying to the
        // same opening again (a forwarded/resent copy, an accidental
        // second email) would otherwise create a duplicate application and
        // re-upload/duplicate the CV file. A different opening is fine.
        if ($matchedOpening) {
            $dupCheck = $pdo->prepare("SELECT 1 FROM job_applications WHERE candidate_email = :e AND job_opening_id = :o LIMIT 1");
            $dupCheck->execute([':e' => $candidateEmail, ':o' => $matchedOpening['id']]);
            if ($dupCheck->fetchColumn()) { $message->setFlag('Seen'); continue; }
        }

        $insert->execute([
            ':job_opening_id' => $matchedOpening['id'] ?? null,
            ':job_title' => $matchedOpening['title'] ?? ($subject ?: 'Unassigned'),
            ':candidate_name' => $candidateName,
            ':candidate_email' => $candidateEmail,
            ':candidate_phone' => $candidatePhone,
            ':cover_letter' => substr($bodyText, 0, 3000),
            ':cv_url' => $cvUrl['url'] ?? null,
            ':portfolio_url' => $portfolioUrl,
            ':portfolio_attachment_url' => $portfolioAttachmentUrl['url'] ?? null,
            ':linkedin_url' => $linkedinUrl,
            ':ai_review_status' => $cvUrl ? 'pending' : 'no_cv',
            ':email_message_id' => $messageId,
        ]);
        // job_applications.id defaults to UUID() at the DB level, so
        // lastInsertId() (auto_increment-only) is useless here — look it
        // up by the message id we just inserted (unique) instead.
        $lookup->execute([':mid' => $messageId]);
        $newId = $lookup->fetchColumn();
        if ($newId) log_activity($pdo, $newId, 'Captured from email');

        if ($newId && $cvUrl) {
            $cvContent = null;
            if (!empty($cvUrl['is_doc'])) {
                // Legacy .doc — can't extract text (no library available server-side
                // for the old binary format) — tag it instead of scoring garbage.
                $pdo->prepare("UPDATE job_applications SET ai_score=0, ai_summary='Legacy .doc file could not be read — please ask the candidate for a PDF or .docx.', ai_review_status='cv_error' WHERE id=:id")->execute([':id' => $newId]);
            } elseif (!empty($cvUrl['is_docx'])) {
                $docxText = extract_docx_text(base64_decode($cvUrl['base64']));
                if ($docxText) {
                    $cvContent = ["type" => "text", "text" => substr($docxText, 0, 20000)];
                } else {
                    $pdo->prepare("UPDATE job_applications SET ai_score=0, ai_summary='Could not extract text from this Word document.', ai_review_status='cv_error' WHERE id=:id")->execute([':id' => $newId]);
                }
            } else {
                $cvContent = ["type" => "document", "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => $cvUrl['base64']]];
            }
            if ($cvContent) {
                ai_review_cv($pdo, $newId, $cvContent, $matchedOpening['title'] ?? null, $matchedOpening['description'] ?? '', $matchedOpening['requirements'] ?? '');
            }
        } elseif ($newId) {
            // No CV — score 0 and tag it rather than spend an AI call on it.
            $pdo->prepare("UPDATE job_applications SET ai_score=0, ai_summary='No CV attached.' WHERE id=:id")->execute([':id' => $newId]);
        }

        // One email, not two: a plain "thanks for applying" if we already
        // have everything we need, or a single combined "thanks — and
        // please complete these details" when something's missing.
        // Sending two separate emails per application (previously: an
        // always-on confirmation, then a second completion email if
        // needed) doubled the outbound volume for no benefit — the
        // completion request reads naturally as an extra paragraph on the
        // same email.
        if ($newId && $confirmationEnabled) {
            $missing = [];
            if (!$cvUrl) $missing['cv'] = 'Your CV';
            if (!$candidatePhone) $missing['phone'] = 'Your phone number';
            if ($candidateName === '' || strcasecmp($candidateName, $candidateEmail) === 0) $missing['name'] = 'Your full name';
            $missing['expected_salary'] = 'Your expected salary';
            $missing['available_start_date'] = 'Your available start date';
            $missing['open_to_task'] = 'Whether you\'re open to a paid test task';

            $confirmMessage = str_replace('{{job}}', $matchedOpening ? htmlspecialchars($matchedOpening['title']) : '', $confirmationMessage);
            $bodyHtml = '<h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:#111827">Thanks for applying, ' . htmlspecialchars($candidateName ?: 'there') . '!</h2>'
                . '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">' . $confirmMessage . '</p>';

            $emailSubject = $confirmationSubject;
            if (!empty($missing)) {
                $token = bin2hex(random_bytes(24));
                $pdo->prepare("UPDATE job_applications SET completion_token=:t WHERE id=:id")->execute([':t' => $token, ':id' => $newId]);
                $completeUrl = 'https://socialflow.admepro.com/careers/complete?token=' . $token;
                $missingListHtml = '<ul style="margin:0 0 16px;padding-left:18px;font-size:14px;line-height:1.8;color:#4b5563">'
                    . implode('', array_map(fn($m) => '<li>' . htmlspecialchars($m) . '</li>', $missing))
                    . '</ul>';
                $completeIntro = str_replace('{{job}}', $matchedOpening ? htmlspecialchars($matchedOpening['title']) : '', $completionMessage);
                $bodyHtml .= '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">' . $completeIntro . '</p>'
                    . $missingListHtml
                    . '<p style="margin:0 0 20px"><a href="' . htmlspecialchars($completeUrl) . '" style="display:inline-block;padding:12px 24px;background:#d90b2c;color:#ffffff;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none">Complete My Application</a></p>';
                $emailSubject = $completionSubject;
            } else {
                $bodyHtml .= '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">Thanks again for your interest in joining us — we appreciate the time you took to apply.</p>';
            }

            $bodyHtml .= '<table width="100%" style="border-top:1px solid #e5e7eb;margin-top:24px;padding-top:20px"><tr><td>'
                . '<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#111827">Admepro Recruitment Team</p>'
                . '<p style="margin:0;font-size:13px;color:#6b7280">145 El Banafsig 3, New Cairo, Cairo</p>'
                . '<p style="margin:0;font-size:13px;color:#6b7280">hello@admepro.com &middot; +20 100 037 0140</p>'
                . '</td></tr></table>';
            $emailSent = send_recruitment_email($pdo, $candidateEmail, $emailSubject, email_base($bodyHtml), $confirmationFromName);
            if ($emailSent) {
                log_activity($pdo, $newId, empty($missing) ? 'Welcome email sent' : 'Welcome + complete-your-application email sent');
            } else {
                log_activity($pdo, $newId, 'Welcome email FAILED to send (see server error log)');
            }
        }

        $message->setFlag('Seen');
        $processed++;
    } catch (Throwable $e) {
        // Don't let one malformed email kill the whole run — log and move on.
        fwrite(STDERR, "Failed processing message: " . $e->getMessage() . "\n");
    }
}

echo json_encode(['checked' => count($messages), 'processed' => $processed]);
