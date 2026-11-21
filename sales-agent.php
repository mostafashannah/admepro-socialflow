<?php
/**
 * sales-agent.php — AI prospect research + outreach (admin + account team).
 * Research runs in the background (CLI) because web searches take minutes; the UI polls job_status.
 */
require_once __DIR__ . '/sales-lib.php';

function saJobFile($id) { return sys_get_temp_dir() . '/sf-sales-job-' . preg_replace('/[^a-f0-9]/', '', $id) . '.json'; }

/* ───────── background worker: php sales-agent.php job <id> ───────── */
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'job' || empty($argv[2])) exit(1);
    $file = saJobFile($argv[2]); $job = json_decode((string)@file_get_contents($file), true); if (!$job) exit(1);
    $save = function ($patch) use ($file, &$job) { $job = array_merge($job, $patch); file_put_contents($file, json_encode($job, JSON_UNESCAPED_UNICODE)); };
    $save(['status' => 'running', 'step' => 'Searching the web for matching companies…']);
    $pdo = gPdo();
    $known = $pdo->query("SELECT domain FROM sales_prospects WHERE domain IS NOT NULL ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
    $prompt = "You are a B2B sales researcher for admepro, a creative & digital marketing agency in Cairo (branding, social media management, content & video production, websites, performance ads).\n\n"
        . "Find {$job['count']} REAL companies that match this brief:\n\"{$job['brief']}\"\nLocation: {$job['country']}.\n\n"
        . "Rules:\n- Only companies you verified exist via web search, with their real official website.\n"
        . "- For each, find signals that they need marketing help (weak/inactive social, new launch, hiring marketers, outdated site, expansion, new branch…).\n"
        . "- Decision makers: only people publicly listed (company site team page, press, public LinkedIn result titles). Give name + role + public profile URL. Never guess or invent emails or phone numbers; include an email/phone only if it is published on an official page, and give that page as source.\n"
        . "- HARD RULE: every company you return MUST have at least one way to reach it that you found on an official page: a published email address or a phone / WhatsApp number (a general inbox or main phone counts). A company with neither is useless to us — do not return it, find another.
- Skip these domains we already have: " . (implode(', ', array_slice($known, 0, 150)) ?: 'none') . "\n\n"
        . "Return ONLY a JSON array: [{\"company\":\"\",\"website\":\"https://…\",\"industry\":\"\",\"city\":\"\",\"country\":\"\",\"score\":1-100,\"why_fit\":\"2 sentences\",\"signals\":[\"…\"],"
        . "\"contacts\":[{\"name\":\"\",\"role\":\"\",\"profile_url\":\"\",\"email\":\"\",\"phone\":\"\",\"source\":\"url\"}],\"company_email\":\"\",\"company_phone\":\"\",\"sources\":[\"url\"]}]";
    [$ok, $text, $sources, $err] = gClaude([
        'max_tokens' => 16000, 'thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'medium'],
        'tools' => [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 25], ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 15]],
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], 600);
    if (!$ok) { $save(['status' => 'error', 'error' => $err]); exit; }
    $list = gJson($text);
    if (!is_array($list) || !array_is_list($list)) { $save(['status' => 'error', 'error' => 'The agent did not return a usable list. Try a more specific brief.']); exit; }
    $save(['step' => 'Saving prospects' . (defined('HUNTER_API_KEY') && HUNTER_API_KEY ? ' and finding verified emails…' : '…')]);
    $added = 0;
    foreach (array_slice($list, 0, 20) as $p) {
        if (!is_array($p) || empty($p['company'])) continue;
        $site = trim((string)($p['website'] ?? ''));
        $domain = $site ? preg_replace('/^www\./', '', strtolower((string)parse_url(preg_match('#^https?://#', $site) ? $site : "https://$site", PHP_URL_HOST))) : null;
        $contacts = array_values(array_filter((array)($p['contacts'] ?? []), 'is_array'));
        foreach ($contacts as &$c) { $c['email'] = gEmail($c['email'] ?? '') ?: ''; $c['verified'] = false; $c['origin'] = 'web'; } unset($c);
        if (!empty($p['company_email']) && gEmail($p['company_email'])) $contacts[] = ['name' => '', 'role' => 'General inbox', 'email' => gEmail($p['company_email']), 'phone' => (string)($p['company_phone'] ?? ''), 'source' => $site, 'origin' => 'web', 'verified' => false];
        // Hunter.io: verified work emails for senior people at this domain
        if ($domain && defined('HUNTER_API_KEY') && HUNTER_API_KEY) {
            $ch = curl_init('https://api.hunter.io/v2/domain-search?' . http_build_query(['domain' => $domain, 'limit' => 5, 'type' => 'personal', 'api_key' => HUNTER_API_KEY]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            $h = json_decode((string)curl_exec($ch), true); curl_close($ch);
            foreach ($h['data']['emails'] ?? [] as $e) {
                if (empty($e['value'])) continue;
                $contacts[] = ['name' => trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')), 'role' => (string)($e['position'] ?? ''), 'email' => strtolower($e['value']),
                    'phone' => (string)($e['phone_number'] ?? ''), 'profile_url' => (string)($e['linkedin'] ?? ''), 'confidence' => (int)($e['confidence'] ?? 0),
                    'verified' => (($e['verification']['status'] ?? '') === 'valid'), 'origin' => 'hunter', 'source' => 'hunter.io'];
            }
        }
        $contacts = saReachable($contacts);   // hard rule: only contacts with an email or a mobile number survive
        if (!$contacts) { $skippedNoContact = ($skippedNoContact ?? 0) + 1; continue; }
        $srcs = array_values(array_unique(array_filter(array_merge((array)($p['sources'] ?? []), array_column($contacts, 'source')), fn($u) => is_string($u) && preg_match('#^https?://#', $u))));
        try {
            $pdo->prepare("INSERT INTO sales_prospects (company, domain, website, industry, country, city, score, why_fit, signals, sources, contacts, status, brief, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?)")
                ->execute([mb_substr($p['company'], 0, 200), $domain ?: null, $site ?: null, mb_substr((string)($p['industry'] ?? ''), 0, 120), mb_substr((string)($p['country'] ?? $job['country']), 0, 80),
                    mb_substr((string)($p['city'] ?? ''), 0, 80), max(0, min(100, (int)($p['score'] ?? 50))), (string)($p['why_fit'] ?? ''), json_encode(array_values((array)($p['signals'] ?? [])), JSON_UNESCAPED_UNICODE),
                    json_encode($srcs, JSON_UNESCAPED_SLASHES), json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($job['brief'], 0, 500), $job['by'], gNow()]);
            $added++;
        } catch (PDOException $e) { if (!str_contains($e->getMessage(), 'Duplicate')) error_log('[sales-agent] ' . $e->getMessage()); }
    }
    $save(['status' => 'done', 'added' => $added, 'found' => count($list), 'step' => "Done — $added new prospects" . (!empty($skippedNoContact) ? " ($skippedNoContact skipped: no email or mobile number found)" : '')]);
    exit;
}

/* ───────── HTTP API ───────── */
$me = gRequireTeam();
$pdo = gPdo();
$act = $_GET['action'] ?? '';
$b = $_SERVER['REQUEST_METHOD'] === 'POST' ? gBody() : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $o = $_SERVER['HTTP_ORIGIN'] ?? ''; if ($o && parse_url($o, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) gOut(403, ['error' => 'Bad origin']); }

function saRow(array $r) { foreach (['signals', 'sources', 'contacts', 'outreach'] as $k) $r[$k] = json_decode((string)$r[$k], true) ?: []; return $r; }
function saGet(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM sales_prospects WHERE id=?"); $st->execute([(int)$id]); $r = $st->fetch(PDO::FETCH_ASSOC); if (!$r) gOut(404, ['error' => 'Not found']); return saRow($r); }
function saMachineSettings(PDO $pdo) {
    $d = ['enabled' => false, 'brief' => '', 'country' => 'Egypt', 'count' => 8, 'lang' => 'en', 'auto_approve_score' => 0, 'auto_send' => false, 'daily_cap' => 20];
    try { $v = $pdo->query("SELECT v FROM seo_settings WHERE k='sales_machine'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; } catch (Throwable $e) { $j = null; }
    return is_array($j) ? array_merge($d, $j) : $d;
}
$capN = defined('SALES_DAILY_CAP') ? (int)SALES_DAILY_CAP : 40;

try {
switch ($act) {
case 'research': {
    $brief = mb_substr(trim($b['brief'] ?? ''), 0, 600); if (mb_strlen($brief) < 8) gOut(400, ['error' => 'Describe who you are looking for']);
    $id = bin2hex(random_bytes(8));
    file_put_contents(saJobFile($id), json_encode(['status' => 'queued', 'step' => 'Starting…', 'brief' => $brief, 'country' => mb_substr(trim($b['country'] ?? 'Egypt'), 0, 80) ?: 'Egypt',
        'count' => max(3, min(15, (int)($b['count'] ?? 8))), 'by' => $me['email'], 'started' => time()], JSON_UNESCAPED_UNICODE));
    $php = defined('PHP_CLI_BIN') ? PHP_CLI_BIN : (is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php');
    exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' job ' . escapeshellarg($id) . ' > /dev/null 2>&1 &');
    gOut(200, ['ok' => true, 'job' => $id]);
}
case 'job_status': {
    $j = json_decode((string)@file_get_contents(saJobFile($_GET['job'] ?? '')), true);
    if (!$j) gOut(404, ['error' => 'Job not found']);
    if ($j['status'] !== 'done' && $j['status'] !== 'error' && time() - ($j['started'] ?? time()) > 900) $j = ['status' => 'error', 'error' => 'The research timed out. Try fewer companies.'];
    gOut(200, array_intersect_key($j, array_flip(['status', 'step', 'error', 'added', 'found'])));
}
case 'list': {
    $st = $_GET['status'] ?? ''; $w = '1=1'; $bind = [];
    if ($st !== '' && $st !== 'all') { $w = 'status=?'; $bind[] = $st; }
    $q = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM sales_sends s WHERE s.prospect_id=p.id) sends FROM sales_prospects p WHERE $w ORDER BY (status='new') DESC, score DESC, id DESC LIMIT 300"); $q->execute($bind);
    $today = (int)$pdo->query("SELECT COUNT(*) FROM sales_sends WHERE sent_at >= CURDATE()")->fetchColumn();
    $counts = $pdo->query("SELECT status, COUNT(*) n FROM sales_prospects GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    gOut(200, ['rows' => array_map('saRow', $q->fetchAll(PDO::FETCH_ASSOC)), 'counts' => $counts, 'sent_today' => $today, 'cap' => $capN,
        'config' => ['hunter' => defined('HUNTER_API_KEY') && HUNTER_API_KEY ? true : false, 'from' => defined('SALES_FROM') ? SALES_FROM : 'Admepro <sales@admepro.com>']]);
}
case 'update': {
    $p = saGet($pdo, $b['id'] ?? 0);
    $status = in_array($b['status'] ?? '', ['new', 'approved', 'contacted', 'replied', 'won', 'rejected'], true) ? $b['status'] : $p['status'];
    $newContacts = isset($b['contacts']) ? saReachable((array)$b['contacts']) : $p['contacts'];
    if (isset($b['contacts']) && !$newContacts && in_array($status, ['approved', 'contacted', 'replied', 'won'], true)) gOut(400, ['error' => 'A prospect must keep at least one contact with an email or a mobile number.']);
    $contacts = json_encode(array_values($newContacts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE sales_prospects SET status=?, contacts=?, updated_at=? WHERE id=?")->execute([$status, $contacts, gNow(), $p['id']]);
    gOut(200, ['ok' => true]);
}
case 'delete': { $pdo->prepare("DELETE FROM sales_prospects WHERE id=?")->execute([(int)($b['id'] ?? 0)]); gOut(200, ['ok' => true]); }
case 'approve': {
    $p = saGet($pdo, $b['id'] ?? 0);
    [$ok, $r] = saApprove($pdo, $p);
    if (!$ok) gOut(400, ['error' => $r]);
    gOut(200, ['ok' => true, 'lead_id' => $r]);
}
case 'draft': {
    $p = saGet($pdo, $b['id'] ?? 0);
    [$ok, $out] = saDraftSequence($p, (int)($b['contact'] ?? 0), ($b['lang'] ?? 'en') === 'ar' ? 'ar' : 'en', (string)($b['angle'] ?? ''));
    if (!$ok) gOut(502, ['error' => $out]);
    $pdo->prepare("UPDATE sales_prospects SET outreach=?, updated_at=? WHERE id=?")->execute([json_encode($out, JSON_UNESCAPED_UNICODE), gNow(), $p['id']]);
    gOut(200, ['ok' => true, 'outreach' => $out]);
}
case 'save_outreach': {
    $p = saGet($pdo, $b['id'] ?? 0);
    $pdo->prepare("UPDATE sales_prospects SET outreach=?, updated_at=? WHERE id=?")->execute([json_encode((array)($b['outreach'] ?? []), JSON_UNESCAPED_UNICODE), gNow(), $p['id']]);
    gOut(200, ['ok' => true]);
}
case 'send': {
    $p = saGet($pdo, $b['id'] ?? 0);
    $to = gEmail($b['to'] ?? ''); $subject = trim($b['subject'] ?? ''); $body = trim($b['body'] ?? ''); $step = max(1, min(3, (int)($b['step'] ?? 1)));
    if (!$to || $subject === '' || $body === '') gOut(400, ['error' => 'Recipient, subject and body are required']);
    $today = (int)$pdo->query("SELECT COUNT(*) FROM sales_sends WHERE sent_at >= CURDATE()")->fetchColumn();
    if ($today >= $capN) gOut(429, ['error' => "Daily limit reached ($capN). This protects the sending domain — continue tomorrow."]);
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]);
    if ((int)$u->fetchColumn() === 1) gOut(400, ['error' => 'This person asked not to be contacted.']);
    $dup = $pdo->prepare("SELECT COUNT(*) FROM sales_sends WHERE prospect_id=? AND to_email=? AND step=?"); $dup->execute([$p['id'], $to, $step]);
    if ((int)$dup->fetchColumn() > 0) gOut(400, ['error' => "Email $step was already sent to this person."]);
    $cid = gUpsertContact($pdo, ['email' => $to, 'company' => $p['company'], 'source' => 'sales_agent', 'tags' => 'prospect', 'consent' => 'lead', 'consent_note' => 'B2B prospect']);
    $optOut = gBase() . '/sales-optout.php?' . http_build_query(['e' => $to, 's' => gSign('so' . $to)]);
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#111">' . nl2br(gH($body))
        . '<p style="margin-top:22px;font-size:11px;color:#9ca3af">Not interested? <a href="' . gH($optOut) . '" style="color:#9ca3af">Let me know with one click</a> and I won\'t email again.</p></div>';
    [$ok, $pid, $err] = gSendEmail(defined('SALES_FROM') ? SALES_FROM : 'Admepro <sales@admepro.com>', $to, $subject, $html, defined('SALES_REPLY_TO') ? SALES_REPLY_TO : null, $optOut);
    if (!$ok) gOut(502, ['error' => 'Send failed: ' . $err]);
    $pdo->prepare("INSERT INTO sales_sends (prospect_id, to_email, step, subject, provider_id, sent_by, sent_at) VALUES (?,?,?,?,?,?,?)")->execute([$p['id'], $to, $step, mb_substr($subject, 0, 250), $pid, $me['email'], gNow()]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status IN ('new','approved'),'contacted',status), updated_at=? WHERE id=?")->execute([gNow(), $p['id']]);
    gOut(200, ['ok' => true, 'sent_today' => $today + 1]);
}
case 'welcome_campaigns': {
    require_once __DIR__ . '/sales-welcome-lib.php';
    gOut(200, ['rows' => [swCampaign($pdo, 'gulf'), swCampaign($pdo, 'egypt')]]);
}
case 'welcome_reset': {
    require_once __DIR__ . '/sales-welcome-lib.php';
    $camp = swCampaign($pdo, ($b['market'] ?? '') === 'gulf' ? 'gulf' : 'egypt'); $d = swDefaultCampaign($camp['audience']['market'] ?? 'egypt');
    $pdo->prepare("UPDATE mkt_campaigns SET subject=?, preheader=?, blocks=?, updated_at=? WHERE id=?")->execute([$d['subject'], $d['preheader'], json_encode($d['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gNow(), $camp['id']]);
    gOut(200, ['ok' => true, 'subject' => $d['subject'], 'preheader' => $d['preheader'], 'blocks' => $d['blocks']]);
}
case 'welcome_sara': {
    // Sara reads the brief and CHOOSES what goes into the introduction email: intro, services, AI content projects, portfolio categories, recent projects, banner.
    // The structure of the email is untouched: the app only fills those sections with her picks (validated against the real website and the service list).
    $brief = mb_substr(trim((string)($b['brief'] ?? '')), 0, 2000); if (mb_strlen($brief) < 8) gOut(400, ['error' => 'Write a short brief for Sara first']);
    $market = ($b['market'] ?? '') === 'gulf' ? 'Gulf (Saudi Arabia, UAE, Kuwait, Qatar...)' : 'Egypt';
    $site = gSiteContent(); $pool = []; foreach ($site['projects'] ?? [] as $it) $pool[$it['url']] = $it;
    $svc = ['social' => 'Social media management', 'brand' => 'Branding and identity', 'video' => 'Video and reels production', 'web' => 'Websites and e-commerce', 'ads' => 'Paid advertising', 'aicontent' => 'AI content creation', 'ai' => 'AI automation and chatbots'];
    $hubs = ['real_estate' => 'Real Estate Marketing', 'retail' => 'E-Commerce & Retail Growth', 'gcc' => 'GCC & KSA Markets', 'ai' => 'AI Content Projects'];
    $menu = ''; foreach ($pool as $it) $menu .= "- {$it['url']} | {$it['title']} | groups: " . implode(',', (array)($it['groups'] ?? [])) . ' | ' . mb_substr((string)$it['summary'], 0, 110) . "\n";
    $sv = ''; foreach ($svc as $k => $v) $sv .= "- $k: $v\n"; $hb = ''; foreach ($hubs as $k => $v) $hb .= "- $k: $v\n";
    $prompt = "You are Sara, admepro's senior content creator. admepro is an AI-powered creative and digital marketing agency in Cairo. We are writing a first INTRODUCTION email to a new contact in this market: $market.\n\n"
        . "Brief from the team:\n$brief\n\nChoose what to include, ONLY from these lists.\nServices (key: name):\n$sv\nPortfolio category pages (key: name):\n$hb\nWebsite projects (url | title | groups | summary):\n" . ($menu ?: "(none available)\n") . "\n"
        . "Rules: pick what fits the brief and the market, not everything. Max 4 services, max 4 portfolio categories, max 4 recent projects. Use real urls from the list only.\n"
        . "intro: \"short\" or \"full\", or your own 2 to 3 sentence agency introduction (plain text, no claims about results or clients you cannot see in the lists).\n"
        . "Return ONLY JSON: {\"subject\":\"...\",\"preheader\":\"...\",\"intro\":\"short|full|text\",\"services\":[keys],\"hubs\":[keys],\"projects\":[urls],\"banner\":\"url of one project whose image fits best, or empty\",\"note\":\"one short sentence on what you chose and why\"}. Subject max 70 chars; you may use {{first_name}} and {{company}}.";
    [$ok, $text, , $err] = gClaude(['max_tokens' => 1200, 'output_config' => ['effort' => 'low'], 'system' => 'Reply with ONE valid JSON object only, no other text.', 'messages' => [['role' => 'user', 'content' => $prompt]]], 90);
    if (!$ok) gOut(502, ['error' => $err ?: 'Sara could not answer, try again']);
    $j = gJson($text); if (!is_array($j)) gOut(502, ['error' => 'Sara\'s answer could not be read, try again']);
    $urls = fn($v, $n) => array_slice(array_values(array_filter((array)$v, fn($u) => is_string($u) && isset($pool[$u]))), 0, $n);
    $keys = fn($v, $allowed, $n) => array_slice(array_values(array_filter((array)$v, fn($k) => is_string($k) && isset($allowed[$k]))), 0, $n);
    $intro = trim((string)($j['intro'] ?? '')); if (!in_array($intro, ['short', 'full'], true)) $intro = mb_substr($intro, 0, 600);
    $banner = (string)($j['banner'] ?? ''); if (!isset($pool[$banner])) $banner = '';
    gOut(200, ['subject' => mb_substr(trim((string)($j['subject'] ?? '')), 0, 120), 'preheader' => mb_substr(trim((string)($j['preheader'] ?? '')), 0, 160), 'intro' => $intro,
        'services' => $keys($j['services'] ?? [], $svc, 4), 'hubs' => $keys($j['hubs'] ?? [], $hubs, 4), 'projects' => $urls($j['projects'] ?? [], 4),
        'banner' => $banner, 'note' => mb_substr(trim((string)($j['note'] ?? '')), 0, 240), 'site_error' => $site['error'] ?? null]);
}
case 'welcome_list': {
    require_once __DIR__ . '/sales-welcome-lib.php';
    $st = $_GET['status'] ?? 'pending'; $w = '1=1'; $bind = [];
    $mk = in_array($_GET['market'] ?? '', ['gulf', 'egypt'], true) ? $_GET['market'] : '';
    $gid = swCampaign($pdo, 'gulf')['id']; $eid = swCampaign($pdo, 'egypt')['id'];
    $mw = $mk !== '' ? "sw.market=" . $pdo->quote($mk) : '1=1';
    $join = "FROM sales_welcome sw LEFT JOIN mkt_contacts k ON k.email = sw.to_email LEFT JOIN mkt_sends ms ON ms.contact_id = k.id AND ms.campaign_id = IF(sw.market='gulf', $gid, $eid)";
    if (in_array($st, ['pending', 'approved', 'rejected', 'failed'], true)) { $w = 'sw.status=?'; $bind[] = $st; }
    $q = $pdo->prepare("SELECT sw.*, ms.opened_at ms_opened, ms.clicked_at ms_clicked, ms.unsub_at ms_unsub $join WHERE $w AND $mw ORDER BY sw.id DESC LIMIT 200"); $q->execute($bind);
    $rows = array_map(function ($r) { $r['opened_at'] = $r['ms_opened']; $r['clicked_at'] = $r['ms_clicked']; $r['unsub_at'] = $r['ms_unsub']; unset($r['ms_opened'], $r['ms_clicked'], $r['ms_unsub']); return $r; }, $q->fetchAll(PDO::FETCH_ASSOC));
    $counts = $pdo->query("SELECT sw.status, COUNT(*) n FROM sales_welcome sw WHERE $mw GROUP BY sw.status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $t = $pdo->query("SELECT COUNT(*) sent, SUM(ms.opened_at IS NOT NULL) opened, SUM(ms.clicked_at IS NOT NULL) clicked, SUM(sw.replied_at IS NOT NULL) replied, SUM(ms.unsub_at IS NOT NULL) unsubs $join WHERE sw.sent_at IS NOT NULL AND $mw")->fetch(PDO::FETCH_ASSOC);
    gOut(200, ['rows' => $rows, 'counts' => $counts, 'totals' => $t, 'cap' => $capN]);
}
case 'welcome_preview': {
    require_once __DIR__ . '/sales-welcome-lib.php';
    $q = $pdo->prepare("SELECT * FROM sales_welcome WHERE id=?"); $q->execute([(int)($b['id'] ?? 0)]); $row = $q->fetch(PDO::FETCH_ASSOC); if (!$row) gOut(404, ['error' => 'Not found']);
    [$subject, $html] = swRenderRow($pdo, $row, null);
    gOut(200, ['subject' => $subject, 'html' => $html]);
}
case 'welcome_update': {
    $id = (int)($b['id'] ?? 0); $q = $pdo->prepare("SELECT * FROM sales_welcome WHERE id=?"); $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC); if (!$row) gOut(404, ['error' => 'Not found']);
    if ($row['sent_at']) gOut(400, ['error' => 'Already sent: it can no longer be edited.']);
    $mk = ($b['market'] ?? $row['market']) === 'gulf' ? 'gulf' : 'egypt';
    $pdo->prepare("UPDATE sales_welcome SET subject=?, personal_line=?, market=?, status=? WHERE id=?")->execute([mb_substr(trim((string)($b['subject'] ?? $row['subject'])), 0, 250), mb_substr(trim((string)($b['personal_line'] ?? $row['personal_line'])), 0, 600), $mk,
        in_array($b['status'] ?? '', ['pending', 'rejected'], true) ? $b['status'] : $row['status'], $id]);
    gOut(200, ['ok' => true]);
}
case 'welcome_send': {
    require_once __DIR__ . '/sales-welcome-lib.php';
    $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [$b['id'] ?? 0]))));
    $sent = 0; $errs = [];
    foreach (array_slice($ids, 0, 40) as $i) { [$ok, $err] = swSend($pdo, $i, $me['email'], $capN); if ($ok) { $sent++; usleep(400000); } else { $errs[] = $err; if (str_contains((string)$err, 'Daily limit')) break; } }
    gOut($sent ? 200 : 400, ['ok' => $sent > 0, 'sent' => $sent, 'error' => $errs ? implode(' · ', array_unique($errs)) : null]);
}
case 'welcome_mark': {
    $id = (int)($b['id'] ?? 0); $fb = mb_substr(trim((string)($b['feedback'] ?? '')), 0, 480);
    $pdo->prepare("UPDATE sales_welcome SET replied_at = IF(?, COALESCE(replied_at, NOW()), NULL), feedback = ? WHERE id=?")->execute([!empty($b['replied']) ? 1 : 0, $fb !== '' ? $fb : null, $id]);
    if (!empty($b['replied'])) $pdo->prepare("UPDATE sales_prospects SET status=IF(status IN ('new','approved','contacted'),'replied',status), updated_at=? WHERE id=(SELECT prospect_id FROM sales_welcome WHERE id=?)")->execute([gNow(), $id]);
    gOut(200, ['ok' => true]);
}
case 'machine_get': { gOut(200, ['settings' => saMachineSettings($pdo)]); }
case 'machine_save': {
    $cur = saMachineSettings($pdo);
    $new = ['enabled' => !empty($b['enabled']), 'brief' => mb_substr(trim((string)($b['brief'] ?? $cur['brief'])), 0, 600), 'country' => mb_substr(trim((string)($b['country'] ?? $cur['country'])), 0, 80) ?: 'Egypt',
        'count' => max(3, min(15, (int)($b['count'] ?? $cur['count']))), 'lang' => ($b['lang'] ?? $cur['lang']) === 'ar' ? 'ar' : 'en',
        'auto_approve_score' => max(0, min(100, (int)($b['auto_approve_score'] ?? $cur['auto_approve_score']))), 'auto_send' => !empty($b['auto_send']),
        'daily_cap' => max(1, min(80, (int)($b['daily_cap'] ?? $cur['daily_cap'])))];
    $pdo->prepare("INSERT INTO seo_settings (k, v) VALUES ('sales_machine', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([json_encode($new)]);
    gOut(200, ['ok' => true, 'settings' => $new]);
}
case 'funnel': {
    $one = fn($sql) => (int)$pdo->query($sql)->fetchColumn();
    gOut(200, [
        'prospects_new' => $one("SELECT COUNT(*) FROM sales_prospects WHERE status='new'"), 'contacted' => $one("SELECT COUNT(*) FROM sales_prospects WHERE status='contacted'"),
        'replied' => $one("SELECT COUNT(*) FROM sales_prospects WHERE status='replied'"), 'won' => $one("SELECT COUNT(*) FROM sales_prospects WHERE status='won'"),
        'sent_7d' => $one("SELECT COUNT(*) FROM sales_sends WHERE sent_at > NOW() - INTERVAL 7 DAY"),
        'leads_30d' => $one("SELECT COUNT(*) FROM leads WHERE created_at > NOW() - INTERVAL 30 DAY"),
        'wa_responders' => $one("SELECT COUNT(*) FROM mkt_contacts WHERE FIND_IN_SET('wa-responder', REPLACE(tags, ', ', ','))"),
        'email_sent_30d' => $one("SELECT COUNT(*) FROM mkt_sends WHERE status='sent' AND sent_at > NOW() - INTERVAL 30 DAY"),
        'email_clicked_30d' => $one("SELECT COUNT(*) FROM mkt_sends WHERE clicked_at IS NOT NULL AND sent_at > NOW() - INTERVAL 30 DAY"),
    ]);
}
case 'sends': { $st = $pdo->prepare("SELECT * FROM sales_sends WHERE prospect_id=? ORDER BY id"); $st->execute([(int)($_GET['id'] ?? 0)]); gOut(200, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]); }
default: gOut(400, ['error' => 'Unknown action']);
}
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "doesn't exist")) gOut(500, ['error' => 'Run migration-marketing.sql and migration-sales-welcome.sql first', 'setup' => true]);
    error_log('[sales-agent] ' . $e->getMessage()); gOut(500, ['error' => 'Database error']);
}
