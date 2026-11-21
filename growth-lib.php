<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
/**
 * growth-lib.php — shared code for Marketing (newsletters / WhatsApp campaigns) and the Sales agent.
 *
 * Optional config.php settings:
 *   MARKETING_FROM      'admepro <news@admepro.com>'   newsletter sender (a separate address protects normal mail)
 *   SALES_FROM          'Admepro <sales@admepro.com>'  outreach sender
 *   SALES_REPLY_TO      'sales@admepro.com'
 *   SALES_DAILY_CAP     40                              outreach emails per day
 *   WA_MKT_PHONE_ID     separate WhatsApp number for marketing (falls back to WA_PHONE_ID)
 *   WA_WABA_ID          WhatsApp Business Account id (lists your approved templates)
 *   HUNTER_API_KEY      verified decision-maker emails (hunter.io)
 *   PUBLIC_BASE_URL     'https://socialflow.admepro.com'
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';

const GROWTH_ROLES = ['admin', 'account_director', 'account_manager', 'account_executive'];

function gOut($code, $p) { http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function gUuid() { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function gNow() { return (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d H:i:s'); }
function gBase() { return defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL ? rtrim(PUBLIC_BASE_URL, '/') : 'https://socialflow.admepro.com'; }
function gSecret() { return hash('sha256', (defined('API_KEY') ? API_KEY : '') . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|growth'); }
function gSign($s) { return substr(hash_hmac('sha256', (string)$s, gSecret()), 0, 20); }
function gCheck($s, $sig) { return is_string($sig) && hash_equals(gSign($s), $sig); }

/** Signed-in admin / account team, or 403. */
function gRequireTeam(array $roles = GROWTH_ROLES) {
    $a = authResolve(); $s = $a['session'] ?? null;
    if (!$s || $s['user_type'] !== 'team' || !in_array($s['role'], $roles, true)) gOut(403, ['error' => 'Not allowed']);
    return $s;
}

function gPhone($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif (preg_match('/^0\d{10}$/', $d)) $d = '20' . substr($d, 1); // local Egyptian 01x…
    return strlen($d) >= 8 ? '+' . $d : null;
}
function gEmail($e) { $e = strtolower(trim((string)$e)); return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null; }

/** Insert or update a contact by email (or phone when there is no email). Never downgrades consent / un-unsubscribes. */
function gUpsertContact(PDO $pdo, array $c) {
    $email = gEmail($c['email'] ?? ''); $phone = gPhone($c['phone'] ?? '');
    if (!$email && !$phone) return null;
    $row = null;
    if ($email) { $st = $pdo->prepare("SELECT * FROM mkt_contacts WHERE email = ?"); $st->execute([$email]); $row = $st->fetch(PDO::FETCH_ASSOC); }
    if (!$row && $phone) { $st = $pdo->prepare("SELECT * FROM mkt_contacts WHERE phone = ? LIMIT 1"); $st->execute([$phone]); $row = $st->fetch(PDO::FETCH_ASSOC); }
    $rank = ['none' => 0, 'lead' => 1, 'opt_in' => 2];
    $consent = $c['consent'] ?? 'lead';
    if ($row) {
        $newConsent = ($rank[$consent] ?? 1) > ($rank[$row['consent']] ?? 1) ? $consent : $row['consent'];
        $tags = implode(',', array_unique(array_filter(array_map('trim', array_merge(explode(',', (string)$row['tags']), explode(',', (string)($c['tags'] ?? '')))))));
        $pdo->prepare("UPDATE mkt_contacts SET email = COALESCE(email, ?), phone = COALESCE(phone, ?), name = COALESCE(NULLIF(name,''), ?), company = COALESCE(NULLIF(company,''), ?),
            lang = COALESCE(lang, ?), tags = ?, consent = ?, consent_at = IF(? <> consent, ?, consent_at), consent_note = IF(? <> consent, ?, consent_note),
            wa_opt_in = GREATEST(wa_opt_in, ?), lead_id = COALESCE(lead_id, ?), client_id = COALESCE(client_id, ?), updated_at = ? WHERE id = ?")
            ->execute([$email, $phone, $c['name'] ?? null, $c['company'] ?? null, $c['lang'] ?? null, $tags ?: null, $newConsent, $newConsent, gNow(), $newConsent, $c['consent_note'] ?? null,
                !empty($c['wa_opt_in']) ? 1 : 0, $c['lead_id'] ?? null, $c['client_id'] ?? null, gNow(), $row['id']]);
        return (int)$row['id'];
    }
    $pdo->prepare("INSERT INTO mkt_contacts (email, phone, name, company, lang, tags, source, consent, consent_at, consent_note, wa_opt_in, lead_id, client_id, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$email, $phone, $c['name'] ?? null, $c['company'] ?? null, $c['lang'] ?? null, $c['tags'] ?? null, $c['source'] ?? 'manual', $consent, gNow(),
            $c['consent_note'] ?? null, !empty($c['wa_opt_in']) ? 1 : 0, $c['lead_id'] ?? null, $c['client_id'] ?? null, gNow()]);
    return (int)$pdo->lastInsertId();
}

/** WHERE clause for a campaign audience. Email: not unsubscribed/bounced, consent opt_in or lead. WhatsApp: explicit WhatsApp opt-in only. */
function gAudienceWhere(array $aud, $channel, array &$bind) {
    $w = [];
    if ($channel === 'whatsapp') { $w[] = "phone IS NOT NULL AND unsub_wa = 0 AND wa_opt_in = 1"; }
    else {
        $w[] = "email IS NOT NULL AND unsub_email = 0 AND bounced = 0";
        $cons = array_values(array_intersect((array)($aud['consent'] ?? ['opt_in', 'lead']), ['opt_in', 'lead']));
        if (!$cons) $cons = ['opt_in'];
        $w[] = "consent IN (" . implode(',', array_fill(0, count($cons), '?')) . ")"; foreach ($cons as $c) $bind[] = $c;
    }
    if (!empty($aud['sources'])) { $s = array_slice((array)$aud['sources'], 0, 20); $w[] = "source IN (" . implode(',', array_fill(0, count($s), '?')) . ")"; foreach ($s as $x) $bind[] = (string)$x; }
    if (!empty($aud['tags'])) { $o = []; foreach (array_slice((array)$aud['tags'], 0, 20) as $t) { $o[] = "FIND_IN_SET(?, REPLACE(tags, ', ', ','))"; $bind[] = trim((string)$t); } $w[] = '(' . implode(' OR ', $o) . ')'; }
    if (!empty($aud['industries'])) { $o = []; foreach (array_slice((array)$aud['industries'], 0, 20) as $t) { $o[] = "FIND_IN_SET(?, REPLACE(tags, ', ', ','))"; $bind[] = 'industry:' . trim((string)$t); } $w[] = '(' . implode(' OR ', $o) . ')'; }
    if (!empty($aud['lang'])) { $w[] = "(lang = ? OR lang IS NULL)"; $bind[] = (string)$aud['lang']; }
    return implode(' AND ', $w);
}

/* ─────────── email rendering ─────────── */
function gH($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gMergeTags($s, array $c) {
    $first = trim(explode(' ', trim((string)($c['name'] ?? '')))[0] ?? '');
    return strtr((string)$s, ['{{name}}' => $first ?: 'there', '{{first_name}}' => $first ?: 'there', '{{company}}' => (string)($c['company'] ?? '')]);
}
/** Portfolio groups a website item belongs to (by keywords in its title, summary, category and tags): gcc | retail | ai | real_estate | food | auto. */
function gSiteGroups(string $hay): array {
    $hay = strtolower($hay);
    $map = ['gcc' => '/uae|dubai|abu dhabi|saudi|ksa|riyadh|jeddah|kuwait|qatar|doha|bahrain|oman|muscat|gulf|gcc|emirat/',
        'retail' => '/retail|fashion|store|shop|e-?commerce|boutique|supermarket|\bmall\b|jewel|apparel|clothing|furniture/',
        'ai' => '/\bai\b|artificial|chatbot|automation|machine learning|\bgpt\b|\bagents?\b/',
        'real_estate' => '/real estate|property|properties|developer|compound|villa|residential|apartment|\btowers?\b|community|developments?/',
        'food' => '/restaurant|cafe|coffee|food|hospitality|hotel|bakery|catering/',
        'auto' => '/\bauto|\bcars?\b|motors|vehicle|automotive/'];
    $out = []; foreach ($map as $k => $re) if (preg_match($re, $hay)) $out[] = $k;
    return $out;
}

/** Links shown in the footer of EVERY email built on the newsletter template. Override in config.php with define('MARKETING_LINKS', ['Website' => 'https://…', …]). */
function gBrandLinks(): array {
    if (defined('MARKETING_LINKS') && is_array(MARKETING_LINKS)) return MARKETING_LINKS;
    return ['Website' => 'https://admepro.com', 'Instagram' => 'https://www.instagram.com/admeproagency', 'Facebook' => 'https://facebook.com/admepro',
        'LinkedIn' => 'https://www.linkedin.com/company/5040327/', 'WhatsApp' => 'https://wa.me/201000531842?text=Hi%20admepro%2C%20I%27m%20interested%20in%20your%20services'];
}
/** Blocks → newsletter HTML in the Admepro style. $trackId (mkt_sends.id) adds open/click tracking + unsubscribe link. */
function gRenderEmail(array $camp, array $contact = [], $sendId = null) {
    $blocks = is_array($camp['blocks'] ?? null) ? $camp['blocks'] : (json_decode((string)($camp['blocks'] ?? '[]'), true) ?: []);
    $rtl = ($camp['lang'] ?? '') === 'ar';
    $link = function ($url) use ($sendId) {
        $url = trim((string)$url);
        if (!preg_match('#^https?://#i', $url)) return gH($url);
        if (!$sendId) return gH($url);
        $q = http_build_query(['c' => $sendId, 'u' => $url, 's' => gSign('c' . $sendId . $url)]);
        return gH(gBase() . '/marketing-track.php?' . $q);
    };
    $body = '';
    foreach ($blocks as $b) {
        $t = $b['type'] ?? '';
        $txt = gMergeTags($b['text'] ?? '', $contact);
        if ($t === 'heading') $body .= '<h2 style="margin:22px 0 8px;font-size:22px;line-height:1.3;font-weight:800;color:#111827">' . gH($txt) . '</h2>';
        elseif ($t === 'text') $body .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#374151">' . nl2br(gH($txt)) . '</p>';
        elseif ($t === 'image' && !empty($b['url'])) $body .= (!empty($b['link']) ? '<a href="' . $link($b['link']) . '">' : '') . '<img src="' . gH($b['url']) . '" alt="' . gH($b['alt'] ?? '') . '" style="display:block;width:100%;max-width:100%;height:auto;border-radius:12px;margin:8px 0 16px"/>' . (!empty($b['link']) ? '</a>' : '');
        elseif ($t === 'button' && !empty($b['url'])) $body .= '<table cellpadding="0" cellspacing="0" style="margin:10px 0 20px"><tr><td style="background:#d90b2c;border-radius:10px"><a href="' . $link($b['url']) . '" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none">' . gH($txt ?: 'Learn more') . '</a></td></tr></table>';
        elseif ($t === 'divider') $body .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:22px 0"/>';
        elseif ($t === 'card' && !empty($b['url'])) $body .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden"><tr><td>'
            . (!empty($b['image']) ? '<a href="' . $link($b['url']) . '"><img src="' . gH($b['image']) . '" alt="' . gH($b['title'] ?? '') . '" style="display:block;width:100%;height:auto;border:0"/></a>' : '')
            . '<div style="padding:14px 16px"><div style="font-size:17px;font-weight:800;color:#111827;line-height:1.35">' . gH(gMergeTags($b['title'] ?? '', $contact)) . '</div>'
            . (!empty($b['text']) ? '<div style="margin-top:6px;font-size:14px;line-height:1.6;color:#4b5563">' . nl2br(gH(gMergeTags($b['text'], $contact))) . '</div>' : '')
            . '<div style="margin-top:12px"><a href="' . $link($b['url']) . '" style="color:#d90b2c;font-weight:700;font-size:14px;text-decoration:none">' . gH($b['button'] ?? 'Read more') . ' →</a></div></div></td></tr></table>';
    }
    $brand = ''; foreach (gBrandLinks() as $lab => $u) $brand .= '<a href="' . $link($u) . '" style="display:inline-block;margin:0 9px 6px;font-size:13px;font-weight:600;color:#374151;text-decoration:underline">' . gH($lab) . '</a>';
    $brand = $brand !== '' ? '<div style="margin:0 0 10px">' . $brand . '</div>' : '';
    $pre = gH(gMergeTags($camp['preheader'] ?? '', $contact));
    $unsub = $sendId ? gBase() . '/marketing-unsub.php?' . http_build_query(['id' => $sendId, 's' => gSign('u' . $sendId)]) : '#';
    $pixel = $sendId ? '<img src="' . gH(gBase() . '/marketing-track.php?' . http_build_query(['o' => $sendId, 's' => gSign('o' . $sendId)])) . '" width="1" height="1" alt="" style="display:block;border:0"/>' : '';
    return '<!DOCTYPE html><html dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '</div>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:28px 12px"><tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td style="padding:24px 32px 4px"><table cellpadding="0" cellspacing="0"><tr><td><img src="' . gH(gBase() . '/icon-192.png') . '" alt="" width="34" height="34" style="display:block;border-radius:8px"/></td><td style="padding-left:10px;font-size:20px;font-weight:800;color:#111827;letter-spacing:-0.3px">admepro</td></tr></table></td></tr>
<tr><td style="padding:10px 32px 20px;text-align:' . ($rtl ? 'right' : 'left') . '">' . $body . '</td></tr>
<tr><td style="padding:18px 32px 26px;border-top:1px solid #f3f4f6;font-size:12px;line-height:1.6;color:#9ca3af;text-align:center">
' . $brand . 'admepro · 145 El Banafsig 3, New Cairo, Cairo, Egypt<br/>You receive this because you are in touch with admepro. <a href="' . gH($unsub) . '" style="color:#6b7280">Unsubscribe</a>
</td></tr></table></td></tr></table>' . $pixel . '</body></html>';
}

function gSendEmail($from, $to, $subject, $html, $replyTo = null, $unsubUrl = null) {
    $payload = ['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html];
    if ($replyTo) $payload['reply_to'] = $replyTo;
    if ($unsubUrl) $payload['headers'] = ['List-Unsubscribe' => '<' . $unsubUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json']]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true);
    return [$code >= 200 && $code < 300, $j['id'] ?? null, $code >= 200 && $code < 300 ? null : mb_substr((string)$res, 0, 400)];
}

/** WhatsApp template message (marketing). $params = body variables in order. */
function gSendWaTemplate($to, $template, $lang, array $params, $headerImage = null) {
    $phoneId = defined('WA_MKT_PHONE_ID') && WA_MKT_PHONE_ID ? WA_MKT_PHONE_ID : (defined('WA_PHONE_ID') ? WA_PHONE_ID : '');
    if (!$phoneId || !defined('WA_ACCESS_TOKEN')) return [false, null, 'WhatsApp is not configured'];
    $components = [];
    if ($headerImage) $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $headerImage]]]];
    if ($params) $components[] = ['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string)$p)), 0, 1000) ?: '-'], $params)];
    $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => $lang ?: 'en_US']]];
    if ($components) $body['template']['components'] = $components;
    $ch = curl_init("https://graph.facebook.com/v19.0/$phoneId/messages");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN, 'Content-Type: application/json']]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true);
    return [$code >= 200 && $code < 300, $j['messages'][0]['id'] ?? null, $code >= 200 && $code < 300 ? null : mb_substr((string)$res, 0, 400)];
}

/**
 * Claude Messages API call (raw HTTP, same as the rest of this PHP codebase).
 * Uses claude-opus-5-5 with server-side refusal fallbacks, and continues `pause_turn`
 * (long web-search turns) up to 4 times. Returns [ok, finalText, sources[], error].
 */
function gClaude(array $req, $timeout = 240) {
    $req += ['model' => 'claude-opus-5-5', 'max_tokens' => 16000];
    $req['fallbacks'] = 'default';
    $messages = $req['messages'];
    $sources = []; $text = '';
    for ($i = 0; $i < 5; $i++) {
        $req['messages'] = $messages;
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_POSTFIELDS => json_encode($req),
            CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'anthropic-beta: server-side-fallback-2026-07-01', 'Content-Type: application/json']]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($err || $code !== 200) return [false, '', [], $err ?: "Claude HTTP $code: " . mb_substr((string)$res, 0, 300)];
        $j = json_decode((string)$res, true);
        foreach ($j['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= $b['text'];
                foreach ($b['citations'] ?? [] as $c) if (!empty($c['url'])) $sources[$c['url']] = $c['title'] ?? $c['url'];
            } elseif (($b['type'] ?? '') === 'web_search_tool_result' && is_array($b['content'] ?? null) && array_is_list($b['content'])) {
                foreach ($b['content'] as $r) if (!empty($r['url'])) $sources[$r['url']] = $r['title'] ?? $r['url'];
            }
        }
        $stop = $j['stop_reason'] ?? '';
        if ($stop === 'refusal') return [false, '', [], 'The request was declined.'];
        if ($stop !== 'pause_turn') return [true, $text, $sources, null];
        // Long server-tool turn paused: send the assistant content back to let it continue.
        $messages[] = ['role' => 'assistant', 'content' => $j['content']];
    }
    return [true, $text, $sources, null];
}

/** First JSON array/object found in a model reply. */
function gJson($text) {
    if (preg_match('/```(?:json)?\s*([\[{][\s\S]*?[\]}])\s*```/', $text, $m)) { $j = json_decode($m[1], true); if ($j !== null) return $j; }
    // Balanced scan: take the first complete {...} or [...] that parses, so a sentence after the JSON (that may contain
    // braces such as {{first_name}}) can no longer break the extraction.
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $o = $text[$i]; if ($o !== '{' && $o !== '[') continue;
        $depth = 0; $inStr = false; $esc = false;
        for ($k = $i; $k < $len; $k++) {
            $ch = $text[$k];
            if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{' || $ch === '[') $depth++;
            elseif ($ch === '}' || $ch === ']') { $depth--; if ($depth === 0) { $j = json_decode(substr($text, $i, $k - $i + 1), true); if ($j !== null) return $j; break; } }
        }
    }
    foreach (['[' => ']', '{' => '}'] as $o => $c) {
        $a = strpos($text, $o); $b = strrpos($text, $c);
        if ($a !== false && $b > $a) { $j = json_decode(substr($text, $a, $b - $a + 1), true); if ($j !== null) return $j; }
    }
    return null;
}
function gPdo() {
    static $pdo = null;
    if (!$pdo) $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    return $pdo;
}
function gBody() { $j = json_decode(file_get_contents('php://input') ?: '[]', true); return is_array($j) ? $j : []; }


/* ─────────── sync leads + clients into marketing contacts (used by the Contacts page and the daily machine) ─────────── */
const G_INDUSTRIES = ['real-estate' => 'Real estate', 'food-beverage' => 'Food & beverage', 'retail-fashion' => 'Retail & fashion', 'health-beauty' => 'Health & beauty',
    'education' => 'Education', 'technology' => 'Technology', 'automotive' => 'Automotive', 'hospitality-travel' => 'Hospitality & travel',
    'finance-insurance' => 'Finance & insurance', 'construction-industry' => 'Construction & industry', 'professional-services' => 'Professional services',
    'media-entertainment' => 'Media & entertainment', 'other' => 'Other'];

/** Cheap keyword match (English + Arabic) from an industry field, a company name or notes → industry slug, or null. */
function gIndustrySlug($text) {
    $t = mb_strtolower((string)$text);
    if (trim($t) === '') return null;
    $map = [
        'real-estate' => ['real estate', 'realestate', 'property', 'developer', 'developments', 'compound', 'عقار', 'تطوير', 'كمبوند'],
        'food-beverage' => ['restaurant', 'cafe', 'café', 'coffee', 'food', 'bakery', 'catering', 'beverage', 'مطعم', 'كافيه', 'قهوة', 'أغذية', 'حلويات'],
        'retail-fashion' => ['fashion', 'clothing', 'apparel', 'retail', 'jewel', 'boutique', 'shoes', 'ملابس', 'أزياء', 'مجوهرات', 'تجزئة'],
        'health-beauty' => ['clinic', 'medical', 'dental', 'hospital', 'pharma', 'health', 'beauty', 'skincare', 'cosmetic', 'salon', 'spa', 'عيادة', 'طبي', 'تجميل', 'صيدلية'],
        'education' => ['school', 'academy', 'university', 'education', 'nursery', 'training', 'مدرسة', 'أكاديمية', 'جامعة', 'تعليم', 'حضانة'],
        'technology' => ['software', 'tech', 'it services', 'saas', 'app ', 'digital solutions', 'برمجيات', 'تقنية'],
        'automotive' => ['auto', 'car ', 'cars', 'motor', 'vehicle', 'سيارات', 'موتورز'],
        'hospitality-travel' => ['hotel', 'resort', 'travel', 'tourism', 'tours', 'فندق', 'سياحة', 'سفر'],
        'finance-insurance' => ['bank', 'finance', 'insurance', 'investment', 'fintech', 'بنك', 'تمويل', 'تأمين', 'استثمار'],
        'construction-industry' => ['construction', 'contracting', 'engineering', 'manufactur', 'factory', 'industrial', 'steel', 'مقاولات', 'هندسة', 'مصنع', 'صناعة'],
        'professional-services' => ['consult', 'law ', 'legal', 'accounting', 'agency', 'recruit', 'محاماة', 'استشارات', 'محاسبة'],
        'media-entertainment' => ['media', 'film', 'music', 'production', 'entertainment', 'events', 'إنتاج', 'إعلام', 'فعاليات'],
    ];
    foreach ($map as $slug => $words) foreach ($words as $w) if (mb_strpos($t, $w) !== false) return $slug;
    return null;
}
function gIndustryTag($text) { $s = gIndustrySlug($text); return $s ? 'industry:' . $s : ''; }

/** AI pass for contacts that still have no industry tag: one call, up to $limit contacts, always ends with a tag (other as a fallback). */
function gClassifyUntaggedIndustries(PDO $pdo, $limit = 40) {
    $st = $pdo->prepare("SELECT id, name, company, email, tags FROM mkt_contacts WHERE (tags IS NULL OR tags NOT LIKE '%industry:%') AND ((company IS NOT NULL AND company <> '') OR (name IS NOT NULL AND name <> '')) AND source IN ('lead','client','sales_agent','import','newsletter','website') ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute(); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $list = ''; foreach ($rows as $r) $list .= $r['id'] . ' | ' . ($r['company'] ?: $r['name']) . ' | ' . substr((string)strrchr((string)$r['email'], '@'), 1) . "\n";
    [$ok, $text] = gClaude(['max_tokens' => 2500, 'output_config' => ['effort' => 'low'], 'system' => 'You classify businesses by industry. Reply with ONE JSON object mapping each id to one slug, nothing else.',
        'messages' => [['role' => 'user', 'content' => "Slugs: " . implode(', ', array_keys(G_INDUSTRIES)) . "\nUse \"other\" only if none fit. Lines are: id | company or person | email domain\n$list\nReturn {\"<id>\":\"<slug>\", …} for every id."]]], 90);
    $j = $ok ? gJson($text) : null; if (!is_array($j)) return 0;
    $n = 0;
    foreach ($rows as $r) {
        $slug = $j[(string)$r['id']] ?? null; if (!is_string($slug) || !isset(G_INDUSTRIES[$slug])) continue;
        $tags = implode(',', array_unique(array_filter(array_merge(array_map('trim', explode(',', (string)$r['tags'])), ['industry:' . $slug]))));
        $pdo->prepare("UPDATE mkt_contacts SET tags = ?, updated_at = ? WHERE id = ?")->execute([$tags, gNow(), $r['id']]); $n++;
    }
    return $n;
}

/* ─────────── sync leads + clients into marketing contacts (used by the Contacts page and the daily machine) ───────────
 * Only REAL leads come across (category lead, not job seekers / service providers / contacts with no number) and each contact
 * is tagged with its industry, so campaigns can target an industry. Returns [contacts synced, contacts newly tagged by AI]. */
function gSyncLeadsAndClients(PDO $pdo, &$tagged = null) {
    $n = 0;
    try {
        foreach ($pdo->query("SELECT * FROM leads WHERE ((email IS NOT NULL AND email<>'') OR (phone IS NOT NULL AND phone<>'')) AND (category IS NULL OR category IN ('', 'lead', 'no_contact')) LIMIT 20000") as $r) {
            $ind = gIndustryTag(($r['industry'] ?? '') ?: ($r['company'] ?? '') . ' ' . ($r['name'] ?? ''));
            if (gUpsertContact($pdo, ['email' => $r['email'], 'phone' => $r['phone'], 'name' => $r['name'], 'company' => $r['company'] ?? null, 'source' => 'lead', 'tags' => trim('lead,' . $ind, ','), 'lead_id' => (string)$r['id'], 'consent' => 'lead', 'consent_note' => 'From leads'])) $n++;
        }
    } catch (Throwable $e) { error_log('[growth] lead sync: ' . $e->getMessage()); }
    try {
        foreach ($pdo->query("SELECT * FROM clients WHERE email IS NOT NULL AND email<>'' LIMIT 5000") as $r) {
            $ind = gIndustryTag(($r['industry'] ?? '') ?: ($r['name'] ?? ''));
            if (gUpsertContact($pdo, ['email' => $r['email'], 'phone' => $r['phone'] ?? null, 'name' => $r['name'], 'company' => $r['name'], 'source' => 'client', 'tags' => trim('client,' . $ind, ','), 'client_id' => (string)$r['id'], 'consent' => 'lead', 'consent_note' => 'Client'])) $n++;
        }
    } catch (Throwable $e) { error_log('[growth] client sync: ' . $e->getMessage()); }
    $tagged = 0; try { $tagged = gClassifyUntaggedIndustries($pdo, 40); } catch (Throwable $e) { error_log('[growth] industry classify: ' . $e->getMessage()); }
    return $n;
}

/* ─────────── website projects + articles for email campaigns ───────────
 * Reads the website repo (same GitHub settings as the Website tab) for project / post JSON, and keeps only items whose page
 * is really live: a URL in https://<site>/sitemap.xml that ends with the item's slug. Cached for 15 minutes. */
function gSiteContent($force = false) {
    $cache = sys_get_temp_dir() . '/sf-site-content.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) { $j = json_decode((string)file_get_contents($cache), true); if (is_array($j)) return $j; }
    $repo = defined('WEBSITE_GITHUB_REPO') ? WEBSITE_GITHUB_REPO : ''; $tok = defined('WEBSITE_GITHUB_TOKEN') ? WEBSITE_GITHUB_TOKEN : '';
    $branch = defined('WEBSITE_GITHUB_BRANCH') && WEBSITE_GITHUB_BRANCH ? WEBSITE_GITHUB_BRANCH : 'main';
    $root = trim(defined('WEBSITE_CONTENT_ROOT') && WEBSITE_CONTENT_ROOT !== null ? WEBSITE_CONTENT_ROOT : 'src/content', '/');
    $site = defined('WEBSITE_URL') && WEBSITE_URL ? rtrim(WEBSITE_URL, '/') : 'https://admepro.com';
    if (!$repo || !$tok) return ['error' => 'Connect the website first (Website → Setup).', 'projects' => [], 'posts' => []];
    $gh = function ($path) use ($tok) {
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github+json', 'User-Agent: socialflow']]);
        $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, json_decode((string)$r, true)];
    };
    // live URLs from the sitemap
    $ch = curl_init($site . '/sitemap.xml'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    $xml = (string)curl_exec($ch); curl_close($ch);
    preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m); $urls = $m[1] ?? [];
    $urlFor = function ($slug) use ($urls) { $best = null; foreach ($urls as $u) { $p = rtrim((string)parse_url($u, PHP_URL_PATH), '/'); if (substr($p, -strlen('/' . $slug)) === '/' . $slug && (!$best || strpos($u, '/ar/') === false)) $best = $u; } return $best; };
    [$c, $tree] = $gh("/repos/$repo/git/trees/" . rawurlencode($branch) . '?recursive=1');
    if ($c !== 200) return ['error' => "GitHub said HTTP $c", 'projects' => [], 'posts' => []];
    $files = ['projects' => [], 'posts' => []];
    foreach ($tree['tree'] ?? [] as $t) {
        if ($t['type'] !== 'blob') continue; $p = $t['path'];
        if (preg_match('#^' . preg_quote($root, '#') . '/projects/([^/_][^/]*)/project\.json$#', $p, $mm)) $files['projects'][$mm[1]] = $p;
        elseif (preg_match('#^' . preg_quote($root, '#') . '/posts/([^/_][^/]*)\.json$#', $p, $mm)) $files['posts'][$mm[1]] = $p;
    }
    $out = ['projects' => [], 'posts' => [], 'site' => $site, 'at' => date('c')];
    foreach ($files as $kind => $list) {
        foreach (array_slice($list, 0, 60, true) as $slug => $path) {
            [$fc, $fr] = $gh("/repos/$repo/contents/" . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch));
            if ($fc !== 200) continue;
            $j = json_decode((string)base64_decode((string)($fr['content'] ?? '')), true); if (!is_array($j)) continue;
            if (array_key_exists('published', $j) && !$j['published']) continue;
            $url = $urlFor($slug); if (!$url) continue; // not live on the site
            $img = $j['cover'] ?? $j['image'] ?? $j['thumbnail'] ?? (is_array($j['images'] ?? null) ? ($j['images'][0] ?? '') : '');
            if (is_array($img)) $img = $img['src'] ?? $img['url'] ?? '';
            if ($img && !preg_match('#^https?://#', (string)$img)) $img = $site . '/' . ltrim((string)$img, '/');
            $out[$kind][] = ['slug' => $slug, 'title' => (string)($j['title'] ?? $j['name'] ?? $slug), 'summary' => mb_substr(strip_tags((string)($j['description'] ?? $j['summary'] ?? $j['excerpt'] ?? $j['seoDescription'] ?? '')), 0, 280),
                'image' => (string)$img, 'url' => $url, 'date' => (string)($j['date'] ?? ''), 'kind' => $kind === 'posts' ? 'article' : 'project',
                'category' => mb_substr(is_scalar($j['category'] ?? null) ? (string)$j['category'] : (is_scalar($j['sector'] ?? null) ? (string)$j['sector'] : (is_scalar($j['industry'] ?? null) ? (string)$j['industry'] : '')), 0, 60),
                'groups' => gSiteGroups(implode(' ', [$slug, (string)($j['title'] ?? $j['name'] ?? ''), (string)($j['description'] ?? $j['summary'] ?? $j['excerpt'] ?? ''), is_scalar($j['category'] ?? null) ? (string)$j['category'] : '', is_scalar($j['sector'] ?? null) ? (string)$j['sector'] : '', is_scalar($j['industry'] ?? null) ? (string)$j['industry'] : '', is_array($j['tags'] ?? null) ? implode(' ', array_filter($j['tags'], 'is_scalar')) : (is_scalar($j['tags'] ?? null) ? (string)$j['tags'] : ''), is_scalar($j['location'] ?? null) ? (string)$j['location'] : '', is_scalar($j['country'] ?? null) ? (string)$j['country'] : '']))];
        }
    }
    // Thumbnails: ask each live page for the preview image it declares itself (og:image), which is always the real, working URL.
    // The project JSON only gives a relative path we can't map reliably. Fetched in parallel, a few seconds in total.
    $refs = []; foreach (['projects', 'posts'] as $kind) foreach ($out[$kind] as $i => $it) $refs[] = [$kind, $i, $it['url']];
    foreach (array_chunk($refs, 20) as $batch) {
        $mh = curl_multi_init(); $hs = [];
        foreach ($batch as $n => [$kind, $i, $url]) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; admepro-newsletter)', CURLOPT_HTTPHEADER => ['Accept: text/html']]);
            curl_multi_add_handle($mh, $c); $hs[$n] = $c;
        }
        do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
        foreach ($batch as $n => [$kind, $i, $url]) {
            $html = (string)curl_multi_getcontent($hs[$n]); curl_multi_remove_handle($mh, $hs[$n]); curl_close($hs[$n]);
            $head = substr($html, 0, 60000);
            if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $head, $m) || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $head, $m)) {
                $img = html_entity_decode($m[1]);
                if (!preg_match('#^https?://#', $img)) $img = rtrim(strpos($img, '//') === 0 ? 'https:' . $img : $site . '/' . ltrim($img, '/'));
                $out[$kind][$i]['image'] = $img;
            }
            if ($out[$kind][$i]['summary'] === '' && preg_match('#<meta[^>]+(?:name|property)=["\'](?:og:description|description)["\'][^>]+content=["\']([^"\']+)#i', $head, $m2)) $out[$kind][$i]['summary'] = mb_substr(html_entity_decode($m2[1]), 0, 280);
        }
        curl_multi_close($mh);
    }
    // Portfolio hub pages (Real estate / E-commerce & retail / GCC & KSA / AI content): the shortest matching page in the sitemap, never an individual project.
    $proj = array_flip(array_column(array_merge($out['projects'], $out['posts']), 'url'));
    $hubRe = ['real_estate' => '#(^|[/-])real-?estate#i', 'retail' => '#e-?commerce|retail#i', 'gcc' => '#(^|[/-])(gcc|ksa|saudi|gulf)([/-]|$)#i', 'ai' => '#(^|[/-])ai([/-]|$)#i'];
    $hubs = [];
    foreach ($hubRe as $k => $re) {
        $best = null;
        foreach ($urls as $u) { if (strpos($u, '/ar/') !== false || isset($proj[$u])) continue; $p = trim((string)parse_url($u, PHP_URL_PATH), '/'); if ($p === '' || !preg_match($re, $p)) continue;
            $d = substr_count($p, '/'); if (!$best || $d < $best[0] || ($d === $best[0] && strlen($p) < $best[1])) $best = [$d, strlen($p), $u]; }
        $hubs[$k] = $best ? $best[2] : '';
    }
    $out['hubs'] = $hubs;
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/* ─────────── WhatsApp marketing: someone replied to a campaign → they become a lead (closes the cycle) ─────────── */
function gCaptureWaResponder(PDO $pdo, $fromDigits, $text) {
    $st = $pdo->prepare("SELECT k.id, k.name, k.company, k.phone, k.lead_id, k.tags, c.id campaign_id, c.name campaign_name
        FROM mkt_sends s JOIN mkt_campaigns c ON c.id = s.campaign_id AND c.channel = 'whatsapp' JOIN mkt_contacts k ON k.id = s.contact_id
        WHERE s.status = 'sent' AND REPLACE(k.phone, '+', '') = ? AND s.sent_at > NOW() - INTERVAL 14 DAY ORDER BY s.sent_at DESC LIMIT 1");
    $st->execute([preg_replace('/\D/', '', (string)$fromDigits)]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) return null;
    $leadId = $k['lead_id'];
    if (!$leadId) {
        // Only a real prospect becomes a lead — not a job seeker, a supplier pitching us, or noise.
        try { require_once __DIR__ . '/reply-bot-lib.php'; $cls = function_exists('classifyClientContact') ? classifyClientContact('admepro', (string)$text) : null; } catch (Throwable $e) { $cls = null; }
        if ($cls && in_array($cls['category'], ['other', 'hiring', 'service_provider'], true)) {
            $tags = implode(',', array_unique(array_filter(array_merge(explode(',', (string)$k['tags']), ['wa-responder']))));
            $pdo->prepare("UPDATE mkt_contacts SET tags = ?, updated_at = ? WHERE id = ?")->execute([$tags, gNow(), $k['id']]);
            return null;
        }
        $am = null; try { require_once __DIR__ . '/reply-bot-lib.php'; $am = function_exists('assignLeadRotation') ? assignLeadRotation($pdo) : null; } catch (Throwable $e) {}
        $notes = "Replied to the WhatsApp campaign \"{$k['campaign_name']}\".\n\nTheir message: " . mb_substr((string)$text, 0, 500);
        $leadId = gUuid();
        try {
            $pdo->prepare("INSERT INTO leads (id, name, company, phone, source, status, platforms, notes, category, assigned_to, assigned_at) VALUES (?,?,?,?,'whatsapp_marketing','new',?,?,'lead',?,?)")
                ->execute([$leadId, $k['name'] ?: $k['phone'], $k['company'], $k['phone'], json_encode(['whatsapp']), $notes, $am['email'] ?? null, $am ? gNow() : null]);
        } catch (Throwable $e) {
            $pdo->prepare("INSERT INTO leads (id, name, phone, source, status, platforms, notes, category) VALUES (?,?,?,'whatsapp_marketing','new',?,?,'lead')")
                ->execute([$leadId, $k['name'] ?: $k['phone'], $k['phone'], json_encode(['whatsapp']), $notes]);
        }
    }
    $tags = implode(',', array_unique(array_filter(array_merge(explode(',', (string)$k['tags']), ['wa-responder']))));
    $pdo->prepare("UPDATE mkt_contacts SET tags = ?, lead_id = COALESCE(lead_id, ?), wa_opt_in = 1, updated_at = ? WHERE id = ?")->execute([$tags, $leadId, gNow(), $k['id']]);
    return ['lead_id' => $leadId, 'campaign' => $k['campaign_name'], 'name' => $k['name']];
}
