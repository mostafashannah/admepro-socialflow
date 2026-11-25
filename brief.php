<?php
/**
 * brief.php — the short brief form a lead fills in from the link the account manager sent (Send brief shortcut). Public page, no login:
 * the link carries a signed token tied to ONE lead, so it cannot be guessed or used for another lead.
 * The answers fill the lead's Business Profile, are added to its notes ("Brief received …") and the assigned account manager is notified.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead-send-lib.php';
date_default_timezone_set('Africa/Cairo');
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$token = (string)($_REQUEST['t'] ?? ''); $leadId = lead_brief_lead_id($token);
$ar = (($_REQUEST['l'] ?? '') === 'ar');
$T = $ar ? [
    'dir' => 'rtl', 'title' => 'ملخص سريع عن مشروعك', 'intro' => 'علشان نجهزلك أنسب عرض، ياريت تجاوب على الأسئلة دي (دقيقتين بس).',
    'biz' => 'اسم النشاط / الشركة', 'web' => 'الموقع أو حساب إنستجرام', 'stage' => 'مرحلة النشاط', 'startup' => 'بداية جديدة', 'established' => 'شغّال ومستقر', 'well_known' => 'معروف في السوق',
    'services' => 'إيه الخدمات اللي محتاجها؟', 'goal' => 'إيه أهم هدف دلوقتي؟', 'budget' => 'الميزانية الشهرية التقريبية', 'timeline' => 'تحب نبدأ إمتى؟',
    'asap' => 'في أقرب وقت', 'month' => 'خلال شهر', 'quarter' => 'خلال 2-3 شهور', 'exploring' => 'لسه بستكشف', 'notes' => 'أي حاجة تانية تحب نعرفها', 'send' => 'إرسال', 'other' => 'العربية / English',
    'ok_t' => 'شكراً لك!', 'ok_p' => 'وصلنا الملخص، وفريقنا هيتواصل معاك قريب.', 'bad' => 'الرابط ده مش صالح. اطلب رابط جديد من فريق admepro.', 'switch' => 'English',
] : [
    'dir' => 'ltr', 'title' => 'A quick brief about your project', 'intro' => 'To prepare the right proposal for you, please answer these questions (2 minutes).',
    'biz' => 'Business / company name', 'web' => 'Website or Instagram', 'stage' => 'Business stage', 'startup' => 'Just starting', 'established' => 'Established', 'well_known' => 'Well known in the market',
    'services' => 'Which services do you need?', 'goal' => 'What is your main goal right now?', 'budget' => 'Approximate monthly budget', 'timeline' => 'When would you like to start?',
    'asap' => 'As soon as possible', 'month' => 'Within a month', 'quarter' => 'In 2-3 months', 'exploring' => 'Still exploring', 'notes' => 'Anything else we should know', 'send' => 'Send', 'other' => 'English / العربية',
    'ok_t' => 'Thank you!', 'ok_p' => 'We received your brief and our team will contact you shortly.', 'bad' => 'This link is not valid. Please ask the admepro team for a new one.', 'switch' => 'العربية',
];
$SERVICES = ["Social Media Management","Paid Advertising","Branding & Design","Content Creation","Video Production","Website Development","Influencer Marketing","Email Marketing","Consulting / Strategy"];
function page($T, $body, $e) {
    echo '<!doctype html><html lang="' . ($T['dir'] === 'rtl' ? 'ar' : 'en') . '" dir="' . $T['dir'] . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $e($T['title']) . ' · admepro</title>'
       . '<style>*{box-sizing:border-box}body{margin:0;background:#f6f6f7;font-family:"Montserrat",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#17171a;line-height:1.5}.w{max-width:620px;margin:0 auto;padding:20px 16px 50px}'
       . '.logo{font-weight:800;font-size:22px;letter-spacing:-.02em;margin:6px 0 18px}.logo i{color:#d90b2c;font-style:normal}.card{background:#fff;border:1px solid #e8e8ec;border-radius:18px;padding:22px}'
       . 'h1{font-size:21px;margin:0 0 6px}p.i{color:#5b5b66;margin:0 0 18px;font-size:14px}label{display:block;font-weight:700;font-size:13px;margin:16px 0 6px}'
       . 'input[type=text],select,textarea{width:100%;font:inherit;font-size:15px;padding:11px 12px;border:1px solid #d9d9df;border-radius:12px;background:#fff;color:inherit}textarea{min-height:84px;resize:vertical}'
       . '.chips{display:flex;flex-wrap:wrap;gap:8px}.chips label{display:inline-flex;align-items:center;gap:6px;margin:0;font-weight:600;font-size:13px;border:1px solid #d9d9df;border-radius:99px;padding:7px 12px;cursor:pointer}'
       . 'button{margin-top:22px;width:100%;font:inherit;font-weight:800;font-size:16px;padding:13px;border:0;border-radius:12px;background:#d90b2c;color:#fff;cursor:pointer}a.sw{font-size:13px;color:#5b5b66}.hp{position:absolute;left:-9999px}</style></head><body><div class="w">'
       . '<div class="logo">admepro<i>.</i></div>' . $body . '</div></body></html>';
}
if (!$leadId) { http_response_code(404); page($T, '<div class="card"><p>' . $e($T['bad']) . '</p></div>', $e); exit; }
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $pdo->prepare("SELECT id, name, notes, assigned_to FROM leads WHERE id = :id LIMIT 1"); $st->execute([':id' => $leadId]); $lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) { http_response_code(404); page($T, '<div class="card"><p>' . $e($T['bad']) . '</p></div>', $e); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (trim((string)($_POST['hp'] ?? '')) !== '') { page($T, '<div class="card"><h1>' . $e($T['ok_t']) . '</h1></div>', $e); exit; }
    $cut = fn($v, $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
    $biz = $cut($_POST['business_name'] ?? '', 120); $web = $cut($_POST['website'] ?? '', 200); $goal = $cut($_POST['goal'] ?? '', 400); $budget = $cut($_POST['budget'] ?? '', 80);
    $stage = in_array($_POST['stage'] ?? '', ['startup', 'established', 'well_known'], true) ? $_POST['stage'] : '';
    $tl = $cut($_POST['timeline'] ?? '', 40); $extra = $cut($_POST['notes'] ?? '', 1200);
    $svc = array_values(array_intersect($SERVICES, (array)($_POST['services'] ?? [])));
    $block = "\n\nBrief received (" . date('Y-m-d H:i') . "):\n" . ($biz !== '' ? "Business: $biz\n" : '') . ($web !== '' ? "Website / Instagram: $web\n" : '') . ($stage !== '' ? "Stage: $stage\n" : '')
        . ($svc ? 'Services: ' . implode(', ', $svc) . "\n" : '') . ($goal !== '' ? "Goal: $goal\n" : '') . ($budget !== '' ? "Monthly budget: $budget\n" : '') . ($tl !== '' ? "Start: $tl\n" : '') . ($extra !== '' ? "Notes: $extra\n" : '');
    $pdo->prepare("UPDATE leads SET notes = :n WHERE id = :id")->execute([':n' => mb_substr((string)$lead['notes'] . $block, 0, 60000), ':id' => $leadId]);
    try {   // Business Profile fields (present once migration-lead-business-profile.sql was run)
        $pdo->prepare("UPDATE leads SET business_name = COALESCE(NULLIF(:b,''), business_name), website = COALESCE(NULLIF(:w,''), website), business_stage = COALESCE(NULLIF(:s,''), business_stage), interested_services = :sv WHERE id = :id")
            ->execute([':b' => $biz, ':w' => $web, ':s' => $stage, ':sv' => json_encode($svc, JSON_UNESCAPED_UNICODE), ':id' => $leadId]);
    } catch (Throwable $ex) { /* profile columns not there: the notes still hold everything */ }
    try { $pdo->prepare("INSERT INTO lead_activities (id, lead_id, type, content, author_name) VALUES (UUID(), :l, 'note', :c, :a)")->execute([':l' => $leadId, ':c' => "📝 Brief received from the lead (form)." . ($goal !== '' ? " Goal: $goal" : ''), ':a' => (string)$lead['name']]); } catch (Throwable $ex) {}
    if (!empty($lead['assigned_to'])) { try { $pdo->prepare("INSERT INTO notifications (id, recipient_email, title, message, type, is_read, link_type, link_id) VALUES (UUID(), :e, :t, :m, 'info', 0, 'lead', :l)")
        ->execute([':e' => $lead['assigned_to'], ':t' => 'Brief received: ' . $lead['name'], ':m' => $lead['name'] . ' filled in the brief form. Open the lead to read it.', ':l' => $leadId]); } catch (Throwable $ex) {} }
    page($T, '<div class="card"><h1>' . $e($T['ok_t']) . '</h1><p class="i">' . $e($T['ok_p']) . '</p></div>', $e); exit;
}
$opt = fn($k) => '<option value="' . $k . '">' . $e($T[$k]) . '</option>';
$chips = ''; foreach ($SERVICES as $s) $chips .= '<label><input type="checkbox" name="services[]" value="' . $e($s) . '"> ' . $e($s) . '</label>';
$sw = '?t=' . urlencode($token) . ($ar ? '' : '&l=ar');
page($T, '<div class="card"><h1>' . $e($T['title']) . '</h1><p class="i">' . $e($T['intro']) . '</p><form method="post" action="?t=' . $e(urlencode($token)) . ($ar ? '&l=ar' : '') . '">'
   . '<input type="hidden" name="t" value="' . $e($token) . '"><input type="hidden" name="l" value="' . ($ar ? 'ar' : 'en') . '"><div class="hp"><input type="text" name="hp" tabindex="-1" autocomplete="off"></div>'
   . '<label>' . $e($T['biz']) . '</label><input type="text" name="business_name" maxlength="120">'
   . '<label>' . $e($T['web']) . '</label><input type="text" name="website" maxlength="200" dir="ltr">'
   . '<label>' . $e($T['stage']) . '</label><select name="stage"><option value=""></option>' . $opt('startup') . $opt('established') . $opt('well_known') . '</select>'
   . '<label>' . $e($T['services']) . '</label><div class="chips">' . $chips . '</div>'
   . '<label>' . $e($T['goal']) . '</label><textarea name="goal" maxlength="400"></textarea>'
   . '<label>' . $e($T['budget']) . '</label><input type="text" name="budget" maxlength="80">'
   . '<label>' . $e($T['timeline']) . '</label><select name="timeline"><option value=""></option>' . $opt('asap') . $opt('month') . $opt('quarter') . $opt('exploring') . '</select>'
   . '<label>' . $e($T['notes']) . '</label><textarea name="notes" maxlength="1200"></textarea>'
   . '<button type="submit">' . $e($T['send']) . '</button></form><p style="text-align:center;margin:16px 0 0"><a class="sw" href="' . $e($sw) . '">' . $e($T['switch']) . '</a></p></div>', $e);
