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
    gOut(200, ['contacts' => $c, 'last30' => $s, 'tags' => array_slice($tags, 0, 40, true), 'sources' => $sources,
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
    $n = 0;
    try { foreach ($pdo->query("SELECT id, name, company, email, phone FROM leads WHERE (email IS NOT NULL AND email<>'') LIMIT 20000") as $r)
        if (gUpsertContact($pdo, ['email' => $r['email'], 'phone' => $r['phone'], 'name' => $r['name'], 'company' => $r['company'], 'source' => 'lead', 'tags' => 'lead', 'lead_id' => (string)$r['id'], 'consent' => 'lead', 'consent_note' => 'From leads'])) $n++; }
    catch (Throwable $e) { error_log('[marketing] lead sync: ' . $e->getMessage()); }
    foreach ($pdo->query("SELECT id, name, email, phone FROM clients WHERE email IS NOT NULL AND email<>'' LIMIT 5000") as $r)
        if (gUpsertContact($pdo, ['email' => $r['email'], 'phone' => $r['phone'], 'name' => $r['name'], 'company' => $r['name'], 'source' => 'client', 'tags' => 'client', 'client_id' => (string)$r['id'], 'consent' => 'lead', 'consent_note' => 'Client'])) $n++;
    gOut(200, ['ok' => true, 'synced' => $n]);
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
    [$ok, , $err] = gSendEmail(defined('MARKETING_FROM') ? MARKETING_FROM : 'admepro <news@admepro.com>', $to, '[TEST] ' . ($b['subject'] ?? ''), $html);
    gOut($ok ? 200 : 400, $ok ? ['ok' => true, 'to' => $to] : ['error' => $err]);
}
case 'schedule': {
    $id = (int)($b['id'] ?? 0); $at = trim($b['at'] ?? '') ?: gNow();
    $st = $pdo->prepare("SELECT * FROM mkt_campaigns WHERE id=?"); $st->execute([$id]); $c = $st->fetch(PDO::FETCH_ASSOC);
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
    // Sara drafts newsletter blocks from a short brief.
    $brief = mb_substr(trim($b['brief'] ?? ''), 0, 2000); if ($brief === '') gOut(400, ['error' => 'Write a short brief']);
    $lang = ($b['lang'] ?? 'en') === 'ar' ? 'Arabic (Egyptian-friendly, professional)' : 'English';
    [$ok, $text, , $err] = gClaude(['max_tokens' => 4000, 'output_config' => ['effort' => 'low'],
        'system' => 'You write email newsletters for admepro, a creative & digital marketing agency in Cairo (branding, social media, websites, production). Warm, confident, concise; no hype, no emojis in headings. Use {{first_name}} once in the greeting.',
        'messages' => [['role' => 'user', 'content' => "Write a newsletter in $lang from this brief:\n\n$brief\n\nReturn ONLY JSON: {\"subject\":\"…\",\"preheader\":\"…\",\"blocks\":[{\"type\":\"heading|text|button|divider\",\"text\":\"…\",\"url\":\"(buttons only, use https://admepro.com if unknown)\"}]}. 4–8 blocks, short paragraphs."]]], 90);
    if (!$ok) gOut(502, ['error' => $err]);
    $j = gJson($text); if (!is_array($j) || empty($j['blocks'])) gOut(502, ['error' => 'Could not draft — try again']);
    gOut(200, $j);
}
default: gOut(400, ['error' => 'Unknown action']);
}
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "doesn't exist")) gOut(500, ['error' => 'Run migration-marketing.sql first', 'setup' => true]);
    error_log('[marketing] ' . $e->getMessage()); gOut(500, ['error' => 'Database error']);
}
