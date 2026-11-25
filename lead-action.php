<?php
/**
 * lead-action.php — one-tap actions on a lead (card shortcuts + the Chat reply box).
 * POST {lead_id, action: no_answer | call_later | not_qualified | reply(+text) | brief | meeting(+date "YYYY-MM-DD", time "HH:MM", type office|phone)}
 *   -> {ok, sent, channel, channel_label, text, send_error?, when?}
 * Sends the message the SAME WAY the lead came in (WhatsApp / Instagram / Messenger), Arabic when the customer wrote in Arabic.
 * The app then moves the card / sets the follow-up date and logs the activity.
 * WhatsApp / Instagram / Messenger only allow free text within 24 hours of the customer's last message; outside that window the send is
 * refused by the platform and reported back (sent:false + the reason).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/lead-send-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$a = authResolve(); $s = $a['session'] ?? null;
if (!$s || ($s['user_type'] ?? '') !== 'team') { http_response_code(403); echo json_encode(['error' => 'Not allowed']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$leadId = preg_replace('/[^A-Za-z0-9-]/', '', (string)($in['lead_id'] ?? '')); $action = (string)($in['action'] ?? '');
if ($leadId === '' || !in_array($action, ['no_answer', 'call_later', 'not_qualified', 'reply', 'brief', 'meeting'], true)) { http_response_code(400); echo json_encode(['error' => 'lead_id and a valid action are required']); exit; }

$pdo = authPdo();
$st = $pdo->prepare("SELECT id, name, phone, client_id, notes FROM leads WHERE id = :id LIMIT 1"); $st->execute([':id' => $leadId]); $lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) { http_response_code(404); echo json_encode(['error' => 'Lead not found']); exit; }
$c = lead_context($pdo, $lead);
$ar = $c['ar']; $first = $c['first']; $nm = $first !== '' ? ' ' . $first : '';
$when = null; $extra = [];

if ($action === 'reply') {
    $text = trim((string)($in['text'] ?? ''));
} elseif ($action === 'meeting') {
    $date = (string)($in['date'] ?? ''); $time = (string)($in['time'] ?? ''); $type = (($in['type'] ?? '') === 'phone') ? 'phone' : 'office';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) { http_response_code(400); echo json_encode(['error' => 'Choose a day and a time for the meeting.']); exit; }
    $when = DateTime::createFromFormat('Y-m-d H:i', "$date $time", new DateTimeZone('Africa/Cairo'));
    if (!$when || $when->getTimestamp() < time() - 60) { http_response_code(400); echo json_encode(['error' => 'The meeting time is in the past.']); exit; }
    $text = lead_meeting_text($when, $type, $ar, $first, 'confirm');
    $extra = ['when' => $when->format('Y-m-d\TH:i'), 'meeting_type' => $type];
} elseif ($action === 'brief') {
    $link = 'https://socialflow.admepro.com/brief.php?t=' . lead_brief_token($leadId) . ($ar ? '&l=ar' : '');
    $text = $ar
        ? "أهلاً{$nm}، علشان نجهزلك أنسب عرض، ياريت تملا الملخص السريع ده (دقيقتين بس):\n{$link}"
        : "Hi{$nm}, to prepare the right proposal for you, please fill in this short brief (2 minutes):\n{$link}";
} else {
    $TXT = [
        'no_answer' => [
            'en' => "Hi{$nm}, this is admepro. We tried to call you but couldn't reach you. Feel free to call us on our agency number " . LEAD_AGENCY_PHONE . ", or we'll call you again tomorrow.",
            'ar' => "أهلاً{$nm}، معاك admepro. حاولنا نتصل بيك ومقدرناش نوصلك. تقدر تتصل بينا في أي وقت على رقم الشركة " . LEAD_AGENCY_PHONE . "، أو هنحاول نتصل بيك تاني بكرة.",
        ],
        'call_later' => [
            'en' => "Hi{$nm}, thanks for your time. We'll call you back at a better time. If you'd rather reach us first, call our agency number " . LEAD_AGENCY_PHONE . ".",
            'ar' => "أهلاً{$nm}، شكراً على وقتك. هنتصل بيك تاني في وقت أنسب. ولو حابب تتواصل معانا قبل كده، اتصل على رقم الشركة " . LEAD_AGENCY_PHONE . ".",
        ],
        'not_qualified' => [
            'en' => "Hi{$nm}, thank you for your interest in admepro. From what you've shared, we may not be the right fit right now, but you're always welcome to message us again whenever your plans change.",
            'ar' => "أهلاً{$nm}، شكراً لاهتمامك بـ admepro. من اللي حضرتك شاركته معانا، ممكن مكنّاش الأنسب حالياً، لكن دايماً تقدر تتواصل معانا تاني لو خططك اتغيرت.",
        ],
    ];
    $text = $TXT[$action][$ar ? 'ar' : 'en'];
}
if ($text === '') { http_response_code(400); echo json_encode(['error' => 'The message is empty']); exit; }
if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);

$r = lead_send($pdo, $lead, $text, $c);
echo json_encode(['ok' => true, 'text' => $text] + $r + $extra, JSON_UNESCAPED_UNICODE);
