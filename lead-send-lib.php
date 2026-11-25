<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * lead-send-lib.php — shared by lead-action.php (card shortcuts + Chat reply), brief.php and the meeting reminder cron.
 * Sends a message to a lead THE SAME WAY the lead came in (WhatsApp / Instagram / Messenger; WhatsApp by phone for other sources),
 * from the agency's own connection, and records it in the lead's chat.
 */
require_once __DIR__ . '/reply-bot-lib.php';

const LEAD_AGENCY_PHONE = '0100 037 0140';
const LEAD_OFFICE_ADDRESS_EN = '145 El Banafsig 3, New Cairo';
const LEAD_OFFICE_MAP = 'https://www.google.com/maps?q=30.0638829,31.4708194';

function lead_secret(): string { return defined('API_KEY') ? (string)API_KEY : (defined('DB_PASS') ? (string)DB_PASS : 'sf'); }
function lead_brief_token(string $leadId): string { return $leadId . '.' . substr(hash_hmac('sha256', 'brief|' . $leadId, lead_secret()), 0, 20); }
function lead_brief_lead_id(string $token): ?string {
    $p = explode('.', $token, 2); if (count($p) !== 2 || !preg_match('/^[A-Za-z0-9-]{8,40}$/', $p[0])) return null;
    return hash_equals(lead_brief_token($p[0]), $token) ? $p[0] : null;
}

/** Where this lead lives: channel, the customer id on that channel, the agency client, language, first name. */
function lead_context(PDO $pdo, array $lead): array {
    $digits = preg_replace('/\D/', '', (string)($lead['phone'] ?? ''));
    $channel = ''; $custId = '';
    if (preg_match('/src_id:(whatsapp|instagram|messenger):(\S+)/', (string)($lead['notes'] ?? ''), $mc)) { $channel = $mc[1]; $custId = $mc[2]; }
    if ($channel === 'whatsapp') $digits = preg_replace('/\D/', '', $custId) ?: $digits;
    if ($channel === '' || $channel === 'whatsapp') { $channel = 'whatsapp'; $custId = $digits; }
    $clientId = (string)($lead['client_id'] ?? ''); $clientName = '';
    if ($clientId === '') { $ag = $pdo->query("SELECT id, name FROM clients WHERE LOWER(TRIM(name)) = 'admepro' ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC); if ($ag) { $clientId = $ag['id']; $clientName = $ag['name']; } }
    else { $q = $pdo->prepare("SELECT name FROM clients WHERE id = :i"); $q->execute([':i' => $clientId]); $clientName = (string)$q->fetchColumn(); }
    $ar = false;
    $ids = $channel === 'whatsapp' ? array_values(array_unique([$digits, '+' . $digits])) : [$custId];
    if ($ids && $ids[0] !== '') {
        $qm = $pdo->prepare("SELECT message_text FROM customer_messages WHERE customer_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND direction = 'in' ORDER BY created_at DESC LIMIT 6");
        $qm->execute($ids);
        foreach ($qm->fetchAll(PDO::FETCH_COLUMN) as $t) { if (preg_match('/\p{Arabic}/u', (string)$t)) { $ar = true; break; } }
    }
    if (!$ar && preg_match('/\p{Arabic}/u', (string)($lead['name'] ?? ''))) $ar = true;
    $first = trim(explode(' ', trim((string)($lead['name'] ?? '')))[0] ?? '');
    if ($first === '' || preg_match('/\d/', $first)) $first = '';
    return ['channel' => $channel, 'cust_id' => $custId, 'digits' => $digits, 'client_id' => $clientId, 'client_name' => $clientName, 'ar' => $ar, 'first' => $first,
            'label' => ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'messenger' => 'Messenger'][$channel]];
}

/** Sends $text to the lead. Returns ['sent' => bool, 'channel', 'channel_label', 'send_error'?]. */
function lead_send(PDO $pdo, array $lead, string $text, ?array $ctx = null): array {
    $c = $ctx ?: lead_context($pdo, $lead);
    $base = ['sent' => false, 'channel' => $c['channel'], 'channel_label' => $c['label']];
    if ($c['channel'] === 'whatsapp' && strlen($c['digits']) < 8) return $base + ['send_error' => 'This lead has no usable phone number, so no message was sent.'];
    $appKey = $c['channel'] === 'whatsapp' ? 'whatsapp' : ($c['channel'] === 'instagram' ? 'instagram' : 'facebook');
    $creds = $c['client_id'] !== '' ? findIntegrationCreds($pdo, $c['client_id'], $appKey) : null;
    if (!$creds) return $base + ['send_error' => "No active {$c['label']} connection for the agency, so nothing was sent."];
    if ($c['channel'] === 'whatsapp') [$code, $body] = sendWhatsAppBotReply($creds['phone_id'], $creds['access_token'], $c['digits'], $text);
    else [$code, $body] = sendMetaDM($c['channel'], $creds['page_id'], $creds['access_token'], $c['cust_id'], $text);
    if ($code < 200 || $code >= 300) {
        $em = (string)($body['error']['message'] ?? ('HTTP ' . $code)); $ec = (int)($body['error']['code'] ?? 0);
        if ($c['channel'] === 'whatsapp' && ($ec === 131047 || stripos($em, 're-engage') !== false)) $em = 'WhatsApp only allows free messages within 24 hours of the customer\'s last message. Call them, or send an approved template.';
        elseif ($c['channel'] !== 'whatsapp' && (stripos($em, 'outside of allowed window') !== false || $ec === 10 || $ec === 100)) $em = $c['label'] . ' only allows replies within 24 hours of the customer\'s last message. ' . $em;
        return $base + ['send_error' => $em];
    }
    try {
        $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status) VALUES (:c,:cn,:ch,:cid,:name,'out',:t,'human','open')")
            ->execute([':c' => $c['client_id'], ':cn' => $c['client_name'], ':ch' => $c['channel'], ':cid' => $c['cust_id'], ':name' => (string)($lead['name'] ?? ''), ':t' => $text]);
    } catch (Throwable $e) { error_log('[lead-send] could not store the chat message: ' . $e->getMessage()); }
    return ['sent' => true, 'channel' => $c['channel'], 'channel_label' => $c['label']];
}

const LEAD_AR_DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const LEAD_AR_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

/** Meeting text. $kind: confirm | reminder. $type: office | phone. */
function lead_meeting_text(DateTime $when, string $type, bool $ar, string $first, string $kind = 'confirm'): string {
    $name = $first !== '' ? ' ' . $first : '';
    $dow = (int)$when->format('w'); $mon = (int)$when->format('n') - 1;
    if ($ar) {
        $time = $when->format('g:i') . ' ' . ($when->format('A') === 'AM' ? 'ص' : 'م');
        $day = LEAD_AR_DAYS[$dow] . ' ' . $when->format('j') . ' ' . LEAD_AR_MONTHS[$mon];
        $where = $type === 'office' ? "هنكون سعداء باستقبالك في مكتبنا: 145 البنفسج 3، القاهرة الجديدة.\nالموقع: " . LEAD_OFFICE_MAP : "هنتصل بيك على نفس الرقم في الميعاد ده.";
        if ($kind === 'reminder') return "أهلاً{$name}، تذكير بميعادك مع admepro النهارده الساعة {$time}.\n{$where}\nمستنيينك!";
        return "أهلاً{$name}، تم تأكيد ميعادك مع admepro يوم {$day} الساعة {$time}.\n{$where}\nلو عايز تغيّر الميعاد رد هنا أو اتصل على " . LEAD_AGENCY_PHONE . ".";
    }
    $time = $when->format('g:i A'); $day = $when->format('l, j F');
    $where = $type === 'office' ? "We'll be happy to see you at our office: " . LEAD_OFFICE_ADDRESS_EN . ".\nMap: " . LEAD_OFFICE_MAP : "We'll call you on this number at that time.";
    if ($kind === 'reminder') return "Hi{$name}, a reminder: your meeting with admepro is today at {$time}.\n{$where}\nSee you soon!";
    return "Hi{$name}, your meeting with admepro is confirmed for {$day} at {$time}.\n{$where}\nTo change the time, just reply here or call " . LEAD_AGENCY_PHONE . ".";
}

/** The standard no-answer / call-later / not-qualified messages (Arabic when $ar). */
function lead_action_text(string $action, bool $ar, string $first): string {
    $nm = $first !== '' ? ' ' . $first : '';
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
    return $TXT[$action][$ar ? 'ar' : 'en'] ?? '';
}
