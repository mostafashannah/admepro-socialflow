<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
/**
 * wa-lib.php — WhatsApp's 24-hour rule workaround for TEAM members.
 * Meta only delivers free-text messages within 24h of the person's last message
 * (error 131047 otherwise). Outside that window we resend the same text inside an
 * approved utility template, so team notifications arrive any time.
 *
 * Template (create once in Meta WhatsApp Manager → Message templates, category UTILITY):
 *   name:     team_update   (or set WA_TEAM_TEMPLATE in config.php)
 *   language: English (US) = en_US  (or set WA_TEAM_TEMPLATE_LANG)
 *   body:     SocialFlow update: {{1}}
 */
function waIsWindowError($httpStatus, $response) {
    if ($httpStatus >= 200 && $httpStatus < 300) {
        // Accepted calls can still fail later; only the synchronous error is handled here.
        return false;
    }
    return (bool)preg_match('/131047|131026|re-?engagement|24 ?hours?|outside.*window/i', (string)$response);
}

function waIsTeamNumber(PDO $pdo, $to) {
    $d = preg_replace('/\D/', '', (string)$to);
    if (strlen($d) < 8) return false;
    $tail = substr($d, -9); // last 9 digits ignore +20 / 0 prefixes
    try {
        $st = $pdo->prepare("SELECT 1 FROM team_members WHERE status = 'active' AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_number,''),'+',''),' ',''),'-',''),'(','') LIKE ? LIMIT 1");
        $st->execute(['%' . $tail]);
        if ($st->fetchColumn()) return true;
        $st = $pdo->prepare("SELECT 1 FROM user_profiles up JOIN team_members tm ON tm.email = up.user_email AND tm.status = 'active' WHERE REPLACE(REPLACE(REPLACE(COALESCE(up.whatsapp_number,''),'+',''),' ',''),'-','') LIKE ? LIMIT 1");
        $st->execute(['%' . $tail]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** True when this number messaged us in the last ~23h (free text is allowed). */
function waWindowOpen(PDO $pdo, $to) {
    $tail = substr(preg_replace('/\D/', '', (string)$to), -9);
    if (!empty($GLOBALS['sf_wa_inbound_from']) && substr($GLOBALS['sf_wa_inbound_from'], -9) === $tail) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM pro_messages WHERE role = 'user' AND phone LIKE ? AND created_at >= (NOW() - INTERVAL 23 HOUR) LIMIT 1");
        $st->execute(['%' . $tail]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) { return true; } // unknown → try free text first
    // Replies to Mai are kept in her report sessions, not in pro_messages — check those too.
    try {
        $st = $pdo->prepare("SELECT tm.id FROM team_members tm LEFT JOIN user_profiles up ON up.user_email = tm.email
            WHERE tm.status = 'active' AND (REPLACE(REPLACE(REPLACE(COALESCE(tm.whatsapp_number,''),'+',''),' ',''),'-','') LIKE ?
               OR REPLACE(REPLACE(REPLACE(COALESCE(up.whatsapp_number,''),'+',''),' ',''),'-','') LIKE ?) LIMIT 1");
        $st->execute(['%' . $tail, '%' . $tail]);
        $mid = $st->fetchColumn();
        if ($mid) {
            $st = $pdo->prepare("SELECT transcript FROM mai_report_sessions WHERE account_manager_id = ? AND started_at >= (NOW() - INTERVAL 2 DAY) ORDER BY started_at DESC LIMIT 6");
            $st->execute([$mid]);
            $cut = time() - 23 * 3600;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tr) {
                foreach ((json_decode((string)$tr, true) ?: []) as $turn) {
                    if (($turn['role'] ?? '') === 'user' && !empty($turn['at']) && strtotime($turn['at']) >= $cut) return true;
                }
            }
        }
    } catch (Throwable $e) { return true; }
    return false;
}

/** Sends $body to $to as the team template. Returns [ok, httpStatus, response]. */
/** Splits a WhatsApp-style text into sections: a line that is only *Bold* starts a section; the first block is the title. */
function waSections($text) {
    $out = [['head' => '', 'items' => []]];
    foreach (preg_split('/\R/', strip_tags($text)) as $line) {
        $line = trim(preg_replace('/^[•\-\s]+/u', '', trim($line)));
        if ($line === '') continue;
        if (preg_match('/^\*([^*]+)\*:?$/u', $line, $m)) { $out[] = ['head' => trim($m[1]), 'items' => []]; continue; }
        if (preg_match('/^Full details:/i', $line)) continue;
        $out[count($out) - 1]['items'][] = rtrim($line, '.');
    }
    // the title block is kept even when short; other empty sections are dropped
    return array_values(array_filter($out, fn($x, $i) => $i === 0 || $x['items'], ARRAY_FILTER_USE_BOTH));
}
/** One-line version: "Title — greeting ┃ *Tasks:* a · b ┃ *Finance:* c · d". */
function waFlatten(array $sections) {
    $parts = [];
    foreach ($sections as $i => $x) {
        $txt = implode(', ', $x['items']);
        $parts[] = $i === 0 ? trim(($x['head'] ? $x['head'] . ' — ' : '') . $txt) : '*' . $x['head'] . ':* ' . $txt;
    }
    return implode(' ┃ ', array_filter($parts, 'strlen'));
}
function waSendTeamTemplate($to, $body) {
    $name = defined('WA_TEAM_TEMPLATE') && WA_TEAM_TEMPLATE ? WA_TEAM_TEMPLATE : 'team_update';
    $lang = defined('WA_TEAM_TEMPLATE_LANG') && WA_TEAM_TEMPLATE_LANG ? WA_TEAM_TEMPLATE_LANG : 'en_US';
    // Template parameters can't contain new lines/tabs or 4+ spaces, max ~1024 chars.
    $sections = waSections((string)$body);
    $params = null;
    // Structured brief template (optional): keeps each section on its own line.
    // Body in Meta, category Utility, 6 variables:
    //   {{1}}\n\n{{2}}\n\n{{3}}\n\n{{4}}\n\n{{5}}\n\n{{6}}\n\nFull details: socialflow.admepro.com
    if (defined('WA_BRIEF_TEMPLATE') && WA_BRIEF_TEMPLATE && count($sections) >= 3) {
        $name = WA_BRIEF_TEMPLATE;
        $title = array_shift($sections);
        $parts = array_map(fn($x) => ($x['head'] ? '*' . $x['head'] . ':* ' : '') . implode(', ', $x['items']), $sections);
        while (count($parts) > 5) { $last = array_pop($parts); $parts[4] .= ' ┃ ' . $last; }
        while (count($parts) < 5) $parts[] = '—';
        $params = array_merge([($title['head'] ? $title['head'] . ' — ' : '') . implode(' ', $title['items'])], $parts);
    } else {
        $params = [waFlatten($sections)];
    }
    $params = array_map(function ($p) { $p = trim(preg_replace('/\s{2,}/', ' ', $p)) ?: '—'; return mb_strlen($p) > 1000 ? mb_substr($p, 0, 997) . '…' : $p; }, $params);
    $payload = json_encode([
        'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
        'template' => ['name' => $name, 'language' => ['code' => $lang],
            'components' => [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => $p], $params)]]],
    ]);
    $ch = curl_init('https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_ACCESS_TOKEN]]);
    $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($status < 200 || $status >= 300) error_log('[wa-lib] team template failed: HTTP ' . $status . ' ' . $res);
    return [$status >= 200 && $status < 300, $status, $res];
}
