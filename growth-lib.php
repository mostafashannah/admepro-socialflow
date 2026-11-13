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
    if (!empty($aud['lang'])) { $w[] = "(lang = ? OR lang IS NULL)"; $bind[] = (string)$aud['lang']; }
    return implode(' AND ', $w);
}

/* ─────────── email rendering ─────────── */
function gH($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gMergeTags($s, array $c) {
    $first = trim(explode(' ', trim((string)($c['name'] ?? '')))[0] ?? '');
    return strtr((string)$s, ['{{name}}' => $first ?: 'there', '{{first_name}}' => $first ?: 'there', '{{company}}' => (string)($c['company'] ?? '')]);
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
    }
    $pre = gH(gMergeTags($camp['preheader'] ?? '', $contact));
    $unsub = $sendId ? gBase() . '/marketing-unsub.php?' . http_build_query(['id' => $sendId, 's' => gSign('u' . $sendId)]) : '#';
    $pixel = $sendId ? '<img src="' . gH(gBase() . '/marketing-track.php?' . http_build_query(['o' => $sendId, 's' => gSign('o' . $sendId)])) . '" width="1" height="1" alt="" style="display:block;border:0"/>' : '';
    return '<!DOCTYPE html><html dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '</div>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:28px 12px"><tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td style="padding:28px 32px 4px"><img src="https://admepro.com/wp-content/uploads/2024/10/adme-p2.png" alt="admepro" style="height:28px;width:auto"/></td></tr>
<tr><td style="padding:10px 32px 20px;text-align:' . ($rtl ? 'right' : 'left') . '">' . $body . '</td></tr>
<tr><td style="padding:18px 32px 26px;border-top:1px solid #f3f4f6;font-size:12px;line-height:1.6;color:#9ca3af;text-align:center">
admepro · 145 El Banafsig 3, New Cairo, Cairo, Egypt<br/>You receive this because you are in touch with admepro. <a href="' . gH($unsub) . '" style="color:#6b7280">Unsubscribe</a>
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
