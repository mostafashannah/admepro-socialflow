<?php
/**
 * lead-action.php — one-tap follow-up shortcuts on a lead card (No answer / Call later / Not qualified).
 * POST {lead_id, action: "no_answer"|"call_later"|"not_qualified"} -> {ok, sent, text, send_error?}
 * Sends the matching message the SAME WAY the lead came in (WhatsApp / Instagram / Messenger; WhatsApp by phone for other sources) (Arabic when the customer wrote in Arabic, else English),
 * and records it in the lead's chat. The app then moves the card / sets the follow-up date and logs the activity.
 * WhatsApp only allows free text within 24 hours of the customer's last message: outside that window the send is refused by WhatsApp,
 * which is reported back (sent:false + the reason) so the person knows to call or use a template.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/reply-bot-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$a = authResolve(); $s = $a['session'] ?? null;
if (!$s || ($s['user_type'] ?? '') !== 'team') { http_response_code(403); echo json_encode(['error' => 'Not allowed']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$leadId = preg_replace('/[^A-Za-z0-9-]/', '', (string)($in['lead_id'] ?? '')); $action = (string)($in['action'] ?? '');
if ($leadId === '' || !in_array($action, ['no_answer', 'call_later', 'not_qualified'], true)) { http_response_code(400); echo json_encode(['error' => 'lead_id and a valid action are required']); exit; }

$pdo = authPdo();
$st = $pdo->prepare("SELECT id, name, phone, client_id, notes FROM leads WHERE id = :id LIMIT 1"); $st->execute([':id' => $leadId]); $lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) { http_response_code(404); echo json_encode(['error' => 'Lead not found']); exit; }
$digits = preg_replace('/\D/', '', (string)$lead['phone']);
// Reply the way the lead came in: the lead's notes carry "src_id:<channel>:<customer id>" when it was created from a chat.
$channel = ''; $custId = '';
if (preg_match('/src_id:(whatsapp|instagram|messenger):(\S+)/', (string)$lead['notes'], $mc)) { $channel = $mc[1]; $custId = $mc[2]; }
if ($channel === 'whatsapp') $digits = preg_replace('/\D/', '', $custId) ?: $digits;
if ($channel === '' || $channel === 'whatsapp') { $channel = 'whatsapp'; if (strlen($digits) < 8) { echo json_encode(['ok' => true, 'sent' => false, 'channel' => 'whatsapp', 'send_error' => 'This lead has no usable phone number, so no message was sent.']); exit; } $custId = $digits; }
$chanLabel = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'messenger' => 'Messenger'][$channel];

// the agency: where the chat lives and which WhatsApp number sends
$clientId = (string)($lead['client_id'] ?? ''); $clientName = '';
if ($clientId === '') { $ag = $pdo->query("SELECT id, name FROM clients WHERE LOWER(TRIM(name)) = 'admepro' ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC); if ($ag) { $clientId = $ag['id']; $clientName = $ag['name']; } }
else { $q = $pdo->prepare("SELECT name FROM clients WHERE id = :i"); $q->execute([':i' => $clientId]); $clientName = (string)$q->fetchColumn(); }

// language: Arabic when the customer's own messages are Arabic
$ar = false;
$ids = $channel === 'whatsapp' ? array_unique([$digits, '+' . $digits]) : [$custId];
$qm = $pdo->prepare("SELECT message_text FROM customer_messages WHERE customer_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND direction = 'in' ORDER BY created_at DESC LIMIT 6");
$qm->execute(array_values($ids));
foreach ($qm->fetchAll(PDO::FETCH_COLUMN) as $t) { if (preg_match('/\p{Arabic}/u', (string)$t)) { $ar = true; break; } }
if (!$ar && preg_match('/\p{Arabic}/u', (string)$lead['name'])) $ar = true;

$first = trim(explode(' ', trim((string)$lead['name']))[0] ?? '');
if ($first === '' || preg_match('/\d/', $first)) $first = '';
$TXT = [
    'no_answer' => [
        'en' => "Hi%s, this is admepro. We tried to call you but couldn't reach you. Feel free to call us on our agency number 0100 037 0140, or we'll call you again tomorrow.",
        'ar' => "أهلاً%s، معاك admepro. حاولنا نتصل بيك ومقدرناش نوصلك. تقدر تتصل بينا في أي وقت على رقم الشركة 0100 037 0140، أو هنحاول نتصل بيك تاني بكرة.",
    ],
    'call_later' => [
        'en' => "Hi%s, thanks for your time. We'll call you back at a better time. If you'd rather reach us first, call our agency number 0100 037 0140.",
        'ar' => "أهلاً%s، شكراً على وقتك. هنتصل بيك تاني في وقت أنسب. ولو حابب تتواصل معانا قبل كده، اتصل على رقم الشركة 0100 037 0140.",
    ],
    'not_qualified' => [
        'en' => "Hi%s, thank you for your interest in admepro. From what you've shared, we may not be the right fit right now, but you're always welcome to message us again whenever your plans change.",
        'ar' => "أهلاً%s، شكراً لاهتمامك بـ admepro. من اللي حضرتك شاركته معانا، ممكن مكنّاش الأنسب حالياً، لكن دايماً تقدر تتواصل معانا تاني لو خططك اتغيرت.",
    ],
];
$text = sprintf($TXT[$action][$ar ? 'ar' : 'en'], $first !== '' ? ($ar ? ' ' . $first : ' ' . $first) : '');

$appKey = $channel === 'whatsapp' ? 'whatsapp' : ($channel === 'instagram' ? 'instagram' : 'facebook');
$creds = $clientId !== '' ? findIntegrationCreds($pdo, $clientId, $appKey) : null;
if (!$creds) { echo json_encode(['ok' => true, 'sent' => false, 'channel' => $channel, 'text' => $text, 'send_error' => "No active {$chanLabel} connection for the agency, so nothing was sent."]); exit; }
if ($channel === 'whatsapp') [$code, $body] = sendWhatsAppBotReply($creds['phone_id'], $creds['access_token'], $digits, $text);
else [$code, $body] = sendMetaDM($channel, $creds['page_id'], $creds['access_token'], $custId, $text);
if ($code < 200 || $code >= 300) {
    $em = (string)($body['error']['message'] ?? ('HTTP ' . $code)); $ec = (int)($body['error']['code'] ?? 0);
    if ($channel === 'whatsapp' && ($ec === 131047 || stripos($em, 're-engage') !== false)) $em = 'WhatsApp only allows free messages within 24 hours of the customer\'s last message. Call them, or send an approved template.';
    elseif ($channel !== 'whatsapp' && (stripos($em, 'outside of allowed window') !== false || $ec === 10 || $ec === 100)) $em = ($channel === 'instagram' ? 'Instagram' : 'Messenger') . ' only allows replies within 24 hours of the customer\'s last message. ' . $em;
    echo json_encode(['ok' => true, 'sent' => false, 'channel' => $channel, 'text' => $text, 'send_error' => $em]); exit;
}
try {
    $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status) VALUES (:c,:cn,:ch,:cid,:name,'out',:t,'human','open')")
        ->execute([':c' => $clientId, ':cn' => $clientName, ':ch' => $channel, ':cid' => $custId, ':name' => (string)$lead['name'], ':t' => $text]);
} catch (Throwable $e) { error_log('[lead-action] could not store the chat message: ' . $e->getMessage()); }
echo json_encode(['ok' => true, 'sent' => true, 'channel' => $channel, 'channel_label' => $chanLabel, 'text' => $text], JSON_UNESCAPED_UNICODE);
