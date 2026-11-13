<?php
/**
 * sales-agent.php — AI prospect research + outreach (admin + account team).
 * Research runs in the background (CLI) because web searches take minutes; the UI polls job_status.
 */
require_once __DIR__ . '/growth-lib.php';

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
        . "- Skip these domains we already have: " . (implode(', ', array_slice($known, 0, 150)) ?: 'none') . "\n\n"
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
    $save(['status' => 'done', 'added' => $added, 'found' => count($list), 'step' => "Done — $added new prospects"]);
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
    $contacts = isset($b['contacts']) ? json_encode(array_values((array)$b['contacts']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($p['contacts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE sales_prospects SET status=?, contacts=?, updated_at=? WHERE id=?")->execute([$status, $contacts, gNow(), $p['id']]);
    gOut(200, ['ok' => true]);
}
case 'delete': { $pdo->prepare("DELETE FROM sales_prospects WHERE id=?")->execute([(int)($b['id'] ?? 0)]); gOut(200, ['ok' => true]); }
case 'approve': {
    // Approve → create a lead (assigned by rotation) + a marketing contact for each email.
    $p = saGet($pdo, $b['id'] ?? 0);
    $main = null; foreach ($p['contacts'] as $c) if (!empty($c['email'])) { $main = $c; break; }
    $leadId = $p['lead_id'];
    if (!$leadId) {
        $notes = "Found by the Sales agent.\n\nWhy: {$p['why_fit']}\nSignals: " . implode('; ', $p['signals']) . "\nWebsite: {$p['website']}\n\nContacts:\n"
            . implode("\n", array_map(fn($c) => '- ' . trim(($c['name'] ?? '') . ' · ' . ($c['role'] ?? '') . ' · ' . ($c['email'] ?? '') . ' ' . ($c['profile_url'] ?? '')), $p['contacts']));
        $am = null; try { require_once __DIR__ . '/reply-bot-lib.php'; $am = function_exists('assignLeadRotation') ? assignLeadRotation($pdo) : null; } catch (Throwable $e) {}
        try {
            $pdo->prepare("INSERT INTO leads (name, company, phone, email, source, status, platforms, notes, category, assigned_to, assigned_at) VALUES (?,?,?,?,'sales_agent','new',?,?,'lead',?,?)")
                ->execute([$main['name'] ?? $p['company'], $p['company'], $main['phone'] ?? null, $main['email'] ?? null, json_encode(['website']), $notes, $am['email'] ?? null, $am ? gNow() : null]);
        } catch (Throwable $e) {
            $pdo->prepare("INSERT INTO leads (name, phone, source, status, platforms, notes, category) VALUES (?,?,'sales_agent','new',?,?,'lead')")
                ->execute([$p['company'], $main['phone'] ?? null, json_encode(['website']), $notes . "\nEmail: " . ($main['email'] ?? '')]);
        }
        $leadId = (string)$pdo->lastInsertId();
    }
    foreach ($p['contacts'] as $c) if (!empty($c['email'])) gUpsertContact($pdo, ['email' => $c['email'], 'name' => $c['name'] ?? null, 'company' => $p['company'], 'source' => 'sales_agent', 'tags' => 'prospect', 'consent' => 'lead', 'consent_note' => 'B2B prospect (public business contact)', 'lead_id' => $leadId]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status='new','approved',status), lead_id=?, updated_at=? WHERE id=?")->execute([$leadId, gNow(), $p['id']]);
    gOut(200, ['ok' => true, 'lead_id' => $leadId]);
}
case 'draft': {
    $p = saGet($pdo, $b['id'] ?? 0);
    $c = $p['contacts'][(int)($b['contact'] ?? 0)] ?? ($p['contacts'][0] ?? []);
    $lang = ($b['lang'] ?? 'en') === 'ar' ? 'Arabic' : 'English';
    $prompt = "Write a 3-email cold outreach sequence in $lang from Mostafa Shannah, founder of admepro (creative & digital marketing agency, Cairo — branding, social media, content/video production, websites, ads; https://admepro.com).\n\n"
        . "Prospect: {$p['company']} ({$p['industry']}, {$p['city']} {$p['country']}) — {$p['website']}\nContact: " . ($c['name'] ?? 'unknown') . ', ' . ($c['role'] ?? '') . "\nWhy they fit: {$p['why_fit']}\nSignals: " . implode('; ', $p['signals']) . "\n"
        . (!empty($b['angle']) ? "Angle to use: " . mb_substr($b['angle'], 0, 400) . "\n" : '')
        . "\nRules: short (70–120 words each), specific to their signals, one clear low-friction ask (15-min call or a free quick audit), no fake claims or invented results, human tone, no emojis, plain text. Email 2 sent 3 days later, email 3 a week later as a polite close.\n"
        . "Return ONLY JSON: [{\"step\":1,\"subject\":\"\",\"body\":\"\"},{\"step\":2,…},{\"step\":3,…}]";
    [$ok, $text, , $err] = gClaude(['max_tokens' => 4000, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $prompt]]], 120);
    if (!$ok) gOut(502, ['error' => $err]);
    $seq = gJson($text); if (!is_array($seq) || !$seq) gOut(502, ['error' => 'Could not draft — try again']);
    $out = ['contact' => (int)($b['contact'] ?? 0), 'lang' => $b['lang'] ?? 'en', 'steps' => array_values($seq), 'drafted_at' => gNow()];
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
case 'sends': { $st = $pdo->prepare("SELECT * FROM sales_sends WHERE prospect_id=? ORDER BY id"); $st->execute([(int)($_GET['id'] ?? 0)]); gOut(200, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]); }
default: gOut(400, ['error' => 'Unknown action']);
}
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "doesn't exist")) gOut(500, ['error' => 'Run migration-marketing.sql first', 'setup' => true]);
    error_log('[sales-agent] ' . $e->getMessage()); gOut(500, ['error' => 'Database error']);
}
