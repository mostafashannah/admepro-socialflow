<?php
/** marketing.php — newsletters + WhatsApp campaigns (admin + account team). */
require_once __DIR__ . '/growth-lib.php';
$me = gRequireTeam();
$pdo = gPdo();
$act = $_GET['action'] ?? '';
$b = $_SERVER['REQUEST_METHOD'] === 'POST' ? gBody() : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o && parse_url($o, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) gOut(403, ['error' => 'Bad origin']);
}

function campRow(array $r) { $r['blocks'] = json_decode((string)$r['blocks'], true) ?: []; $r['audience'] = json_decode((string)$r['audience'], true) ?: []; $r['wa_params'] = json_decode((string)$r['wa_params'], true) ?: []; return $r; }
function audCount(PDO $pdo, array $aud, $ch) { $bind = []; $w = gAudienceWhere($aud, $ch, $bind); $st = $pdo->prepare("SELECT COUNT(*) FROM mkt_contacts WHERE $w"); $st->execute($bind); return (int)$st->fetchColumn(); }

try {
switch ($act) {
case 'overview': {
    $c = $pdo->query("SELECT COUNT(*) total, SUM(consent='opt_in') opt_in, SUM(consent='lead') leads, SUM(unsub_email=1) unsub, SUM(wa_opt_in=1 AND unsub_wa=0) wa FROM mkt_contacts")->fetch(PDO::FETCH_ASSOC);
    $s = $pdo->query("SELECT COUNT(*) sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked FROM mkt_sends WHERE status='sent' AND sent_at > NOW() - INTERVAL 30 DAY")->fetch(PDO::FETCH_ASSOC);
    $tags = []; foreach ($pdo->query("SELECT tags FROM mkt_contacts WHERE tags IS NOT NULL AND tags<>'' LIMIT 5000") as $r) foreach (explode(',', $r['tags']) as $t) { $t = trim($t); if ($t !== '') $tags[$t] = ($tags[$t] ?? 0) + 1; }
    arsort($tags);
    $sources = $pdo->query("SELECT source, COUNT(*) n FROM mkt_contacts GROUP BY source ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $industries = []; foreach (G_INDUSTRIES as $slug => $label) { $q = $pdo->prepare("SELECT COUNT(*) FROM mkt_contacts WHERE email IS NOT NULL AND unsub_email = 0 AND bounced = 0 AND FIND_IN_SET(?, REPLACE(tags, ', ', ','))"); $q->execute(['industry:' . $slug]); $c = (int)$q->fetchColumn(); if ($c) $industries[$slug] = ['label' => $label, 'n' => $c]; }
    gOut(200, ['contacts' => $c, 'last30' => $s, 'tags' => array_slice($tags, 0, 40, true), 'sources' => $sources, 'industries' => $industries,
        'config' => ['from' => defined('MARKETING_FROM') ? MARKETING_FROM : 'admepro <news@admepro.com>', 'wa_marketing_number' => defined('WA_MKT_PHONE_ID') && WA_MKT_PHONE_ID ? 'separate' : 'main', 'templates' => defined('WA_WABA_ID') && WA_WABA_ID ? true : false,
            'signup_url' => gBase() . '/newsletter-signup.php']]);
}
case 'contacts': {
    $q = trim($_GET['q'] ?? ''); $f = $_GET['filter'] ?? ''; $page = max(0, (int)($_GET['page'] ?? 0));
    $w = ['1=1']; $bind = [];
    if ($q !== '') { $w[] = "(email LIKE ? OR name LIKE ? OR company LIKE ? OR phone LIKE ?)"; array_push($bind, "%$q%", "%$q%", "%$q%", "%$q%"); }
    if ($f === 'opt_in' || $f === 'lead') { $w[] = "consent = ?"; $bind[] = $f; }
    if ($f === 'unsub') $w[] = "unsub_email = 1";
    if ($f === 'wa') $w[] = "wa_opt_in = 1 AND unsub_wa = 0";
    if (strpos($f, 'industry:') === 0) { $w[] = "FIND_IN_SET(?, REPLACE(tags, ', ', ','))"; $bind[] = $f; }
    if ($f === 'responder') $w[] = "FIND_IN_SET('wa-responder', REPLACE(tags, ', ', ','))";
    $st = $pdo->prepare("SELECT * FROM mkt_contacts WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 100 OFFSET " . ($page * 100)); $st->execute($bind);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM mkt_contacts WHERE " . implode(' AND ', $w)); $cnt->execute($bind);
    gOut(200, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$cnt->fetchColumn()]);
}
case 'contact_save': {
    if (!empty($b['id'])) {
        $pdo->prepare("UPDATE mkt_contacts SET name=?, company=?, phone=?, lang=?, tags=?, consent=?, wa_opt_in=?, unsub_email=?, unsub_wa=?, updated_at=? WHERE id=?")
            ->execute([$b['name'] ?? null, $b['company'] ?? null, gPhone($b['phone'] ?? ''), $b['lang'] ?: null, $b['tags'] ?? null, in_array($b['consent'] ?? '', ['opt_in', 'lead', 'none'], true) ? $b['consent'] : 'lead',
                !empty($b['wa_opt_in']) ? 1 : 0, !empty($b['unsub_email']) ? 1 : 0, !empty($b['unsub_wa']) ? 1 : 0, gNow(), (int)$b['id']]);
        gOut(200, ['ok' => true]);
    }
    $id = gUpsertContact($pdo, $b + ['source' => 'manual', 'consent_note' => 'Added by ' . $me['email']]);
    if (!$id) gOut(400, ['error' => 'Email or phone required']);
    gOut(200, ['ok' => true, 'id' => $id]);
}
case 'contact_delete': { $pdo->prepare("DELETE FROM mkt_contacts WHERE id=?")->execute([(int)($b['id'] ?? 0)]); gOut(200, ['ok' => true]); }
case 'import': {
    // rows: [{email,name,company,phone,tags}], consent: opt_in|lead, note: where they came from (kept as proof of consent)
    $rows = array_slice((array)($b['rows'] ?? []), 0, 5000); $consent = ($b['consent'] ?? '') === 'opt_in' ? 'opt_in' : 'lead';
    $n = 0; foreach ($rows as $r) { if (!is_array($r)) continue;
        if (gUpsertContact($pdo, ['email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '', 'name' => $r['name'] ?? null, 'company' => $r['company'] ?? null,
            'tags' => trim(($r['tags'] ?? '') . ',' . ($b['tags'] ?? ''), ','), 'source' => 'import', 'consent' => $consent, 'consent_note' => mb_substr((string)($b['note'] ?? 'CSV import'), 0, 250), 'wa_opt_in' => !empty($b['wa_opt_in'])])) $n++; }
    gOut(200, ['ok' => true, 'imported' => $n]);
}
case 'sync': {
    // Pull leads + clients that have an email/phone into contacts (consent 'lead' — existing business relationship).
    $tagged = 0; $n = gSyncLeadsAndClients($pdo, $tagged);
    gOut(200, ['ok' => true, 'synced' => $n, 'tagged' => $tagged]);
}
case 'growth': {
    // Real counters: every number comes from rows with a timestamp, grouped per day (Cairo dates).
    $days = max(7, min(60, (int)($_GET['days'] ?? 14)));
    $dbNow = strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn()); $off = (int)round((strtotime(gNow()) - $dbNow) / 3600);  // DB clock → Cairo
    $own = "(category IS NULL OR category IN ('', 'lead')) AND (client_name IS NULL OR client_name = '' OR LOWER(TRIM(client_name)) = 'admepro')";
    $defs = [
        'prospects'  => ['DATETIME', 'sales_prospects', 'created_at', '1=1', 0],
        'outreach'   => ['DATETIME', 'sales_sends', 'sent_at', '1=1', 0],
        'newsletter' => ['DATETIME', 'mkt_sends', 'sent_at', "status = 'sent'", $off],
        'opens'      => ['DATETIME', 'mkt_sends', 'opened_at', '1=1', $off],
        'clicks'     => ['DATETIME', 'mkt_sends', 'clicked_at', '1=1', $off],
        'contacts'   => ['DATETIME', 'mkt_contacts', 'created_at', '1=1', 0],
        'leads'      => ['TIMESTAMP', 'leads', 'created_at', $own, $off],
        'wa_leads'   => ['TIMESTAMP', 'leads', 'created_at', $own . " AND source = 'whatsapp_marketing'", $off],
    ];
    $dates = []; for ($i = $days - 1; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime(gNow() . " -$i days"));
    $out = [];
    foreach ($defs as $key => [, $table, $col, $where, $o]) {
        $by = [];
        try {
            $st = $pdo->prepare("SELECT DATE($col + INTERVAL $o HOUR) d, COUNT(*) n FROM $table WHERE $where AND $col IS NOT NULL AND $col >= ? GROUP BY d");
            $st->execute([date('Y-m-d H:i:s', strtotime($dates[0] . ' 00:00:00') - 86400)]);
            $by = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { error_log("[growth] $key: " . $e->getMessage()); }
        $vals = array_map(fn($d) => (int)($by[$d] ?? 0), $dates);
        $half = intdiv($days, 2);
        $total = 0; try { $total = (int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $where AND $col IS NOT NULL")->fetchColumn(); } catch (Throwable $e) {}
        $out[$key] = ['series' => $vals, 'today' => end($vals), 'yesterday' => $vals[count($vals) - 2] ?? 0,
            'recent' => array_sum(array_slice($vals, -$half)), 'previous' => array_sum(array_slice($vals, 0, count($vals) - $half)), 'total' => $total];
    }
    gOut(200, ['dates' => $dates, 'days' => $days, 'half' => intdiv($days, 2), 'metrics' => $out, 'as_of' => gNow()]);
}
case 'site_content': { gOut(200, gSiteContent(!empty($_GET['refresh']))); }
case 'responders': {
    $st = $pdo->query("SELECT id, name, company, phone, email, lead_id, updated_at FROM mkt_contacts WHERE FIND_IN_SET('wa-responder', REPLACE(tags, ', ', ',')) ORDER BY updated_at DESC LIMIT 100");
    gOut(200, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'audience_count': { gOut(200, ['count' => audCount($pdo, (array)($b['audience'] ?? []), ($b['channel'] ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email')]); }
case 'campaigns': {
    $rows = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id) total,
        (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id AND s.status='sent') sent,
        (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id AND s.status='failed') failed,
        (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id AND s.opened_at IS NOT NULL) opened,
        (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id AND s.clicked_at IS NOT NULL) clicked,
        (SELECT COUNT(*) FROM mkt_sends s WHERE s.campaign_id=c.id AND s.unsub_at IS NOT NULL) unsubs
        FROM mkt_campaigns c ORDER BY c.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    gOut(200, ['rows' => array_map('campRow', $rows)]);
}
case 'campaign_save': {
    $ch = ($b['channel'] ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
    $vals = [$ch, mb_substr(trim($b['name'] ?? 'Untitled'), 0, 200), mb_substr((string)($b['subject'] ?? ''), 0, 250), mb_substr((string)($b['preheader'] ?? ''), 0, 250),
        json_encode(array_values((array)($b['blocks'] ?? [])), JSON_UNESCAPED_UNICODE), $b['wa_template'] ?? null, $b['wa_lang'] ?? 'en_US', json_encode(array_values((array)($b['wa_params'] ?? [])), JSON_UNESCAPED_UNICODE),
        $b['wa_header_image'] ?? null, json_encode((array)($b['audience'] ?? new stdClass), JSON_UNESCAPED_UNICODE)];
    if (!empty($b['id'])) {
        $st = $pdo->prepare("SELECT status FROM mkt_campaigns WHERE id=?"); $st->execute([(int)$b['id']]);
        if (!in_array($st->fetchColumn(), ['draft', 'scheduled'], true)) gOut(400, ['error' => 'This campaign is already sending or sent — duplicate it instead.']);
        $pdo->prepare("UPDATE mkt_campaigns SET channel=?, name=?, subject=?, preheader=?, blocks=?, wa_template=?, wa_lang=?, wa_params=?, wa_header_image=?, audience=?, updated_at=? WHERE id=?")
            ->execute(array_merge($vals, [gNow(), (int)$b['id']]));
        gOut(200, ['ok' => true, 'id' => (int)$b['id']]);
    }
    $pdo->prepare("INSERT INTO mkt_campaigns (channel, name, subject, preheader, blocks, wa_template, wa_lang, wa_params, wa_header_image, audience, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(array_merge($vals, [$me['email'], gNow()]));
    gOut(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
case 'campaign_delete': {
    $id = (int)($b['id'] ?? 0);
    $ck = $pdo->prepare("SELECT audience FROM mkt_campaigns WHERE id=?"); $ck->execute([$id]); if (str_contains((string)$ck->fetchColumn(), '"auto":"welcome"')) gOut(400, ['error' => 'The welcome emails cannot be deleted. Edit them instead.']);
    $pdo->prepare("DELETE FROM mkt_campaigns WHERE id=? AND status IN ('draft','cancelled','scheduled')")->execute([$id]);
    $pdo->prepare("DELETE FROM mkt_sends WHERE campaign_id=? AND status='queued'")->execute([$id]);
    gOut(200, ['ok' => true]);
}
case 'preview': { gOut(200, ['html' => gRenderEmail(['blocks' => $b['blocks'] ?? [], 'preheader' => $b['preheader'] ?? '', 'lang' => $b['lang'] ?? ''], ['name' => $me['name'] ?? 'Mostafa', 'company' => 'Your company'])]); }
case 'test': {
    $to = gEmail($b['to'] ?? '') ?: gEmail($me['email']);
    if (($b['channel'] ?? 'email') === 'whatsapp') {
        $p = gPhone($b['to'] ?? ''); if (!$p) gOut(400, ['error' => 'Enter a phone number']);
        [$ok, , $err] = gSendWaTemplate(ltrim($p, '+'), $b['wa_template'] ?? '', $b['wa_lang'] ?? 'en_US', array_map(fn($x) => gMergeTags($x, ['name' => 'Mostafa']), (array)($b['wa_params'] ?? [])), $b['wa_header_image'] ?: null);
        gOut($ok ? 200 : 400, $ok ? ['ok' => true] : ['error' => $err]);
    }
    $html = gRenderEmail(['blocks' => $b['blocks'] ?? [], 'preheader' => $b['preheader'] ?? ''], ['name' => $me['name'] ?? '', 'company' => '']);
    $fromAddr = defined('MARKETING_FROM') ? MARKETING_FROM : 'admepro <news@admepro.com>';
    [$ok, $pid, $err] = gSendEmail($fromAddr, $to, '[TEST] ' . ($b['subject'] ?? ''), $html);
    gOut($ok ? 200 : 400, $ok ? ['ok' => true, 'to' => $to, 'from' => $fromAddr, 'id' => $pid] : ['error' => $err]);
}
case 'schedule': {
    $id = (int)($b['id'] ?? 0); $at = trim($b['at'] ?? '') ?: gNow();
    $st = $pdo->prepare("SELECT * FROM mkt_campaigns WHERE id=?"); $st->execute([$id]); $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c && str_contains((string)$c['audience'], '"auto":"welcome"')) gOut(400, ['error' => 'This welcome email is sent automatically to each new lead after you approve it in the Sales agent queue. It cannot be sent as a bulk campaign.']);
    if (!$c || !in_array($c['status'], ['draft', 'scheduled'], true)) gOut(400, ['error' => 'Campaign not found or already sent']);
    if ($c['channel'] === 'email' && (trim($c['subject']) === '' || !json_decode($c['blocks'], true))) gOut(400, ['error' => 'Add a subject and some content first']);
    if ($c['channel'] === 'whatsapp' && !$c['wa_template']) gOut(400, ['error' => 'Choose an approved WhatsApp template']);
    $n = audCount($pdo, json_decode($c['audience'], true) ?: [], $c['channel']);
    if ($n === 0) gOut(400, ['error' => 'Nobody matches this audience']);
    $pdo->prepare("UPDATE mkt_campaigns SET status='scheduled', scheduled_at=?, updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s', strtotime($at)), gNow(), $id]);
    gOut(200, ['ok' => true, 'recipients' => $n]);
}
case 'cancel': {
    $id = (int)($b['id'] ?? 0);
    $pdo->prepare("UPDATE mkt_campaigns SET status = IF(status='scheduled','draft','cancelled'), updated_at=? WHERE id=? AND status IN ('scheduled','sending')")->execute([gNow(), $id]);
    $pdo->prepare("DELETE FROM mkt_sends WHERE campaign_id=? AND status='queued'")->execute([$id]);
    gOut(200, ['ok' => true]);
}
case 'duplicate': {
    $id = (int)($b['id'] ?? 0);
    $pdo->prepare("INSERT INTO mkt_campaigns (channel, name, subject, preheader, blocks, wa_template, wa_lang, wa_params, wa_header_image, audience, created_by, created_at)
        SELECT channel, CONCAT(name,' (copy)'), subject, preheader, blocks, wa_template, wa_lang, wa_params, wa_header_image, audience, ?, ? FROM mkt_campaigns WHERE id=?")->execute([$me['email'], gNow(), $id]);
    gOut(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
case 'wa_templates': {
    if (!defined('WA_WABA_ID') || !WA_WABA_ID) gOut(200, ['rows' => [], 'note' => 'Add WA_WABA_ID to config.php to list your approved templates.']);
    $ch = curl_init('https://graph.facebook.com/v19.0/' . WA_WABA_ID . '/message_templates?limit=100&fields=name,language,status,category,components');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN]]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    $rows = [];
    foreach ($j['data'] ?? [] as $t) {
        if (($t['status'] ?? '') !== 'APPROVED') continue;
        $body = ''; $vars = 0; $img = false;
        foreach ($t['components'] ?? [] as $c) { if ($c['type'] === 'BODY') { $body = $c['text'] ?? ''; preg_match_all('/\{\{\d+\}\}/', $body, $m); $vars = count(array_unique($m[0])); } if ($c['type'] === 'HEADER' && ($c['format'] ?? '') === 'IMAGE') $img = true; }
        $rows[] = ['name' => $t['name'], 'lang' => $t['language'], 'category' => $t['category'], 'body' => $body, 'vars' => $vars, 'image_header' => $img];
    }
    gOut(200, ['rows' => $rows, 'error' => $j['error']['message'] ?? null]);
}
case 'write': {
    // Sara drafts newsletter blocks from a short brief — optionally built around chosen website projects / articles.
    $brief = mb_substr(trim($b['brief'] ?? ''), 0, 2000);
    $items = []; foreach (array_slice((array)($b['items'] ?? []), 0, 6) as $it) if (is_array($it) && !empty($it['url']) && !empty($it['title'])) $items[$it['url']] = ['title' => mb_substr((string)$it['title'], 0, 160), 'summary' => mb_substr((string)($it['summary'] ?? ''), 0, 300), 'image' => (string)($it['image'] ?? ''), 'url' => (string)$it['url'], 'kind' => (string)($it['kind'] ?? 'project')];
    if ($brief === '' && !$items) gOut(400, ['error' => 'Write a short brief or pick something from the website']);
    // "Let Sara choose": no pages ticked, so she picks the 1–3 live pages that best fit the brief.
    if (!$items && !empty($b['auto_pick']) && $brief !== '') {
        $site = gSiteContent(); $pool = [];
        foreach (array_merge($site['projects'] ?? [], $site['posts'] ?? []) as $it) $pool[$it['url']] = $it;
        if ($pool) {
            $menu = ''; foreach ($pool as $it) $menu .= "- {$it['url']} | {$it['kind']} | {$it['title']} — " . mb_substr($it['summary'], 0, 120) . "\n";
            [$okp, $tp, , ] = gClaude(['max_tokens' => 600, 'output_config' => ['effort' => 'low'], 'system' => 'You choose which website pages best support an email. Reply with ONE JSON array of urls only.',
                'messages' => [['role' => 'user', 'content' => "Email brief:\n$brief\n\nLive pages (url | kind | title — summary):\n$menu\nPick the 1 to 3 pages that best fit this brief, only from this list. Return e.g. [\"https://…\"]."]]], 60);
            $urls = $okp ? gJson($tp) : null;
            foreach ((array)$urls as $u) if (is_string($u) && isset($pool[$u]) && count($items) < 3) $items[$u] = ['title' => $pool[$u]['title'], 'summary' => $pool[$u]['summary'], 'image' => $pool[$u]['image'], 'url' => $u, 'kind' => $pool[$u]['kind']];
        }
    }
    $lang = ($b['lang'] ?? 'en') === 'ar' ? 'Arabic (Egyptian-friendly, professional)' : 'English';
    $itemText = ''; foreach ($items as $it) $itemText .= "- [{$it['kind']}] {$it['title']} — {$it['summary']} ({$it['url']})\n";
    $prompt = "Write a newsletter in $lang" . ($brief !== '' ? " from this brief:\n\n$brief" : '') . "\n\n"
        . ($items ? "Build it around these REAL pages from the admepro website. For EACH one, add a block of type \"card\" with the exact title and url given (never invent or change urls), a one-sentence reason to click in \"text\", and \"button\" (e.g. \"See the project\" / \"Read the article\"). Open with a short personal intro and close with a button to https://admepro.com. The goal is to send readers to these pages:\n$itemText\n" : '')
        . "Return ONLY JSON: {\"subject\":\"…\",\"preheader\":\"…\",\"blocks\":[{\"type\":\"heading|text|button|divider|card\",\"text\":\"…\",\"url\":\"(buttons and cards only; https://admepro.com if unknown)\",\"title\":\"(cards only)\",\"button\":\"(cards only)\"}]}. 4–9 blocks, short paragraphs.";
    [$ok, $text, , $err] = gClaude(['max_tokens' => 4000, 'output_config' => ['effort' => 'low'],
        'system' => 'You are Sara, admepro\'s senior content creator, writing email newsletters for admepro, a creative & digital marketing agency in Cairo (branding, social media, websites, production). Warm, confident, concise; no hype, no emojis in headings. Use {{first_name}} once in the greeting. Always point readers to our real projects and articles.',
        'messages' => [['role' => 'user', 'content' => $prompt]]], 90);
    if (!$ok) gOut(502, ['error' => $err]);
    $j = gJson($text);
    if (!is_array($j) || empty($j['blocks'])) { // one retry with a stricter reminder
        error_log('[marketing] write: unparsable reply: ' . mb_substr((string)$text, 0, 400));
        [$ok2, $text2, , $err2] = gClaude(['max_tokens' => 4000, 'output_config' => ['effort' => 'low'], 'system' => 'Reply with ONE valid JSON object only, no other text.', 'messages' => [['role' => 'user', 'content' => $prompt . "\n\nIMPORTANT: output only the JSON object, nothing before or after it."]]], 90);
        $j = $ok2 ? gJson($text2) : null;
        if (!is_array($j) || empty($j['blocks'])) gOut(502, ['error' => 'Sara could not draft this one — try again, or pick fewer pages.']);
    }
    // Cards may only point at the pages that were chosen; fill image/title from the real item.
    $j['blocks'] = array_values(array_filter(array_map(function ($bl) use ($items) {
        if (($bl['type'] ?? '') !== 'card') return $bl;
        $it = $items[$bl['url'] ?? ''] ?? null; if (!$it) return null;
        return ['type' => 'card', 'title' => $it['title'], 'text' => (string)($bl['text'] ?? $it['summary']), 'url' => $it['url'], 'image' => $it['image'], 'button' => (string)($bl['button'] ?? 'Read more')];
    }, (array)$j['blocks'])));
    gOut(200, $j);
}
default: gOut(400, ['error' => 'Unknown action']);
}
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "doesn't exist")) gOut(500, ['error' => 'Run migration-marketing.sql first', 'setup' => true]);
    error_log('[marketing] ' . $e->getMessage()); gOut(500, ['error' => 'Database error']);
}
