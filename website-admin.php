<?php
/**
 * website-admin.php — backend for SocialFlow's Website tab (admins only).
 *
 *   GET  ?action=stats&days=30           visitor analytics from site_events + website leads
 *   GET  ?action=status                  is the GitHub connection set up / working
 *   GET  ?action=list&dir=projects|posts|pages
 *   GET  ?action=get&path=…              one content file (text) + its sha
 *   POST ?action=put    {path, content, sha?, message}     save a content file → commit → Hostinger redeploys
 *   POST ?action=upload {path, base64, message}            add/replace an image
 *
 * The website lives in its own GitHub repo; Hostinger deploys it when the branch changes.
 * config.php needs:
 *   define('WEBSITE_GITHUB_REPO',   'owner/repo');
 *   define('WEBSITE_GITHUB_TOKEN',  'github_pat_…');   // fine-grained, Contents: Read & write, that repo only
 *   define('WEBSITE_GITHUB_BRANCH', 'main');           // optional
 *   define('WEBSITE_CONTENT_ROOT',  'src/content');    // optional, where pages/posts/projects live
 *   define('WEBSITE_URL',           'https://admepro.com'); // optional
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function wOut($code, $p) { http_response_code($code); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$a = authResolve();
$s = $a['session'] ?? null;
if (!$s || $s['user_type'] !== 'team' || $s['role'] !== 'admin') wOut(403, ['error' => 'Admins only']);

$pdo = authPdo();
$action = $_GET['action'] ?? '';
$REPO = defined('WEBSITE_GITHUB_REPO') ? WEBSITE_GITHUB_REPO : '';
$TOKEN = defined('WEBSITE_GITHUB_TOKEN') ? WEBSITE_GITHUB_TOKEN : '';
$BRANCH = defined('WEBSITE_GITHUB_BRANCH') && WEBSITE_GITHUB_BRANCH ? WEBSITE_GITHUB_BRANCH : 'main';
$ROOT = trim(defined('WEBSITE_CONTENT_ROOT') && WEBSITE_CONTENT_ROOT !== null ? WEBSITE_CONTENT_ROOT : 'src/content', '/');
$SITE = defined('WEBSITE_URL') && WEBSITE_URL ? rtrim(WEBSITE_URL, '/') : 'https://admepro.com';

/* ─────────────────────────── analytics ─────────────────────────── */
if ($action === 'stats') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $tz = new DateTimeZone('Africa/Cairo');
    $to = new DateTime('now', $tz); $from = (clone $to)->modify('-' . ($days - 1) . ' days');
    $pf = (clone $from)->modify('-' . $days . ' days'); $pt = (clone $from)->modify('-1 day');
    $F = $from->format('Y-m-d'); $T = $to->format('Y-m-d');
    $q = function ($sql, $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); };
    $one = function ($sql, $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    try { $pdo->query("SELECT 1 FROM site_events LIMIT 0"); } catch (Throwable $e) { wOut(200, ['setup' => 'missing_table']); }
    $range = "day BETWEEN ? AND ?";
    $tot = $q("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND $range", [$F, $T])[0];
    $prev = $q("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND $range", [$pf->format('Y-m-d'), $pt->format('Y-m-d')])[0];
    $avgSecs = (int)$one("SELECT COALESCE(AVG(secs),0) FROM site_events WHERE event='leave' AND secs BETWEEN 1 AND 3600 AND $range", [$F, $T]);
    // Bounce = visitors who saw only one page that day.
    $bounce = $q("SELECT SUM(c=1) AS one, COUNT(*) AS all_ FROM (SELECT visitor, day, COUNT(*) c FROM site_events WHERE event='pageview' AND $range GROUP BY visitor, day) x", [$F, $T])[0];
    $series = $q("SELECT day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND $range GROUP BY day ORDER BY day", [$F, $T]);
    $pages = $q("SELECT path, MAX(title) AS title, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND $range GROUP BY path ORDER BY views DESC LIMIT 25", [$F, $T]);
    $sources = $q("SELECT COALESCE(utm_source, ref_host, 'Direct') AS source, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND $range GROUP BY source ORDER BY visitors DESC LIMIT 15", [$F, $T]);
    $campaigns = $q("SELECT utm_campaign AS campaign, utm_source AS source, COUNT(DISTINCT visitor) AS visitors FROM site_events WHERE event='pageview' AND utm_campaign IS NOT NULL AND $range GROUP BY campaign, source ORDER BY visitors DESC LIMIT 10", [$F, $T]);
    $devices = $q("SELECT device AS k, COUNT(DISTINCT visitor) AS v FROM site_events WHERE event='pageview' AND $range GROUP BY device ORDER BY v DESC", [$F, $T]);
    $browsers = $q("SELECT browser AS k, COUNT(DISTINCT visitor) AS v FROM site_events WHERE event='pageview' AND $range GROUP BY browser ORDER BY v DESC LIMIT 6", [$F, $T]);
    $countries = $q("SELECT COALESCE(country,'??') AS k, COUNT(DISTINCT visitor) AS v FROM site_events WHERE event='pageview' AND $range GROUP BY k ORDER BY v DESC LIMIT 250", [$F, $T]);
    $langs = $q("SELECT CASE WHEN path LIKE '/ar%' THEN 'Arabic' ELSE 'English' END AS k, COUNT(*) AS v FROM site_events WHERE event='pageview' AND $range GROUP BY k", [$F, $T]);
    $clicks = $q("SELECT CASE WHEN title LIKE 'tel:%' THEN 'Phone' WHEN title LIKE 'mailto:%' THEN 'Email' ELSE 'WhatsApp' END AS k, COUNT(*) AS v FROM site_events WHERE event='click' AND $range GROUP BY k", [$F, $T]);
    $live = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE ts >= ?", [(new DateTime('now', $tz))->modify('-5 minutes')->format('Y-m-d H:i:s')]);
    $livePages = $q("SELECT path, COUNT(DISTINCT visitor) AS v FROM site_events WHERE event='pageview' AND ts >= ? GROUP BY path ORDER BY v DESC LIMIT 5", [(new DateTime('now', $tz))->modify('-5 minutes')->format('Y-m-d H:i:s')]);
    $leads = []; $leadCount = 0;
    try {
        $leads = $q("SELECT id, name, company, phone, email, status, notes, created_at, assigned_to FROM leads WHERE source = 'website' ORDER BY created_at DESC LIMIT 100");
        $leadCount = (int)$one("SELECT COUNT(*) FROM leads WHERE source = 'website' AND DATE(created_at) BETWEEN ? AND ?", [$F, $T]);
    } catch (Throwable $e) {}
    wOut(200, [
        'from' => $F, 'to' => $T, 'days' => $days,
        'totals' => ['views' => (int)$tot['views'], 'visitors' => (int)$tot['visitors'], 'avg_secs' => $avgSecs,
            'bounce' => $bounce['all_'] ? round($bounce['one'] / $bounce['all_'] * 100) : null,
            'leads' => $leadCount, 'conversion' => $tot['visitors'] ? round($leadCount / $tot['visitors'] * 100, 2) : null,
            'prev_views' => (int)$prev['views'], 'prev_visitors' => (int)$prev['visitors']],
        'series' => $series, 'pages' => $pages, 'sources' => $sources, 'campaigns' => $campaigns,
        'devices' => $devices, 'browsers' => $browsers, 'countries' => $countries, 'langs' => $langs, 'clicks' => $clicks,
        'live' => $live, 'live_pages' => $livePages, 'leads' => $leads, 'site' => $SITE,
    ]);
}

/* Live: who is on the site in the last 5 minutes (anonymous, one row per visitor). */
if ($action === 'live') {
    $since = (new DateTime('now', new DateTimeZone('Africa/Cairo')))->modify('-5 minutes')->format('Y-m-d H:i:s');
    try {
        $st = $pdo->prepare("SELECT e.visitor, e.path, e.title, e.country, e.device, e.browser, e.ref_host, e.utm_source, e.ts,
                (SELECT COUNT(*) FROM site_events x WHERE x.visitor=e.visitor AND x.event='pageview' AND x.day=e.day) AS pages,
                (SELECT MIN(ts) FROM site_events x WHERE x.visitor=e.visitor AND x.day=e.day) AS first_ts
            FROM site_events e
            JOIN (SELECT visitor, MAX(id) mid FROM site_events WHERE event='pageview' AND ts >= ? GROUP BY visitor) l ON l.mid = e.id
            ORDER BY e.ts DESC LIMIT 50");
        $st->execute([$since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['visitor'] = substr($r['visitor'], 0, 6); } unset($r);
        wOut(200, ['rows' => $rows, 'now' => (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d H:i:s')]);
    } catch (Throwable $e) { wOut(200, ['rows' => [], 'error' => 'Run migration-website.sql']); }
}

/* Google search keywords via Search Console (Google never sends the keyword to the website itself).
 * config.php: define('GSC_KEY_FILE', '/etc/socialflow/gsc-key.json');  // service-account JSON, outside the web folder
 *             define('GSC_SITE', 'sc-domain:admepro.com');               // or 'https://admepro.com/'            */
function gscToken() {
    $cache = sys_get_temp_dir() . '/sf-gsc-token.json';
    $c = json_decode((string)@file_get_contents($cache), true);
    if (!empty($c['t']) && $c['exp'] > time() + 60) return $c['t'];
    $key = json_decode((string)@file_get_contents(GSC_KEY_FILE), true);
    if (empty($key['private_key']) || empty($key['client_email'])) return null;
    $b64 = fn($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($unsigned, $sig, $key['private_key'], 'sha256WithRSAEncryption')) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($sig)])]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (empty($j['access_token'])) return null;
    @file_put_contents($cache, json_encode(['t' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)])); @chmod($cache, 0600);
    return $j['access_token'];
}
if ($action === 'keywords') {
    if (!defined('GSC_KEY_FILE') || !GSC_KEY_FILE || !is_readable(GSC_KEY_FILE)) wOut(200, ['connected' => false]);
    $tok = gscToken(); if (!$tok) wOut(200, ['connected' => false, 'error' => 'Could not sign in to Google with the key file']);
    $days = max(7, min(480, (int)($_GET['days'] ?? 30)));
    $site = defined('GSC_SITE') && GSC_SITE ? GSC_SITE : 'sc-domain:admepro.com';
    $end = (new DateTime('now'))->modify('-2 days'); $start = (clone $end)->modify('-' . ($days - 1) . ' days');
    $ask = function ($dims, $limit) use ($tok, $site, $start, $end) {
        $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'), 'dimensions' => $dims, 'rowLimit' => $limit])]);
        $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
        return $j;
    };
    $q = $ask(['query'], 100);
    if (isset($q['error'])) wOut(200, ['connected' => false, 'error' => $q['error']['message'] ?? 'Search Console error']);
    $c = $ask(['country'], 50);
    $map = fn($rows, $k) => array_map(fn($r) => [$k => $r['keys'][0], 'clicks' => (int)$r['clicks'], 'impressions' => (int)$r['impressions'], 'ctr' => round($r['ctr'] * 100, 1), 'position' => round($r['position'], 1)], $rows ?? []);
    wOut(200, ['connected' => true, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'queries' => $map($q['rows'] ?? [], 'query'), 'countries' => $map($c['rows'] ?? [], 'country')]);
}

/* ─────────────────────────── GitHub content ─────────────────────────── */
function gh($method, $url, $token, $body = null) {
    $ch = curl_init('https://api.github.com' . $url);
    $h = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: SocialFlow-Website-Tab'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$res, true)];
}
function safePath($p, $ROOT) {
    $p = trim(str_replace('\\', '/', (string)$p), '/');
    if ($p === '' || strpos($p, '..') !== false || preg_match('#(^|/)\.#', $p)) return null;
    // Only the website's content folder and its blog image folder can be changed from here.
    $ok = ($ROOT === '' ? true : strpos($p . '/', $ROOT . '/') === 0) || strpos($p, 'src/assets/img/site/blog/') === 0;
    return $ok ? $p : null;
}
$ghPath = fn($p) => implode('/', array_map('rawurlencode', explode('/', $p)));

if (in_array($action, ['status', 'list', 'get', 'put', 'upload'], true) && (!$REPO || !$TOKEN)) {
    wOut(200, ['connected' => false, 'error' => 'GitHub is not connected yet — add WEBSITE_GITHUB_REPO and WEBSITE_GITHUB_TOKEN to config.php.']);
}

if ($action === 'status') {
    [$c, $r] = gh('GET', "/repos/$REPO", $TOKEN);
    if ($c !== 200) wOut(200, ['connected' => false, 'error' => "GitHub said HTTP $c — check the token and repo name.", 'repo' => $REPO]);
    [$c2, $r2] = gh('GET', "/repos/$REPO/commits?sha=" . rawurlencode($BRANCH) . "&per_page=8", $TOKEN);
    $commits = $c2 === 200 ? array_map(fn($x) => ['sha' => substr($x['sha'], 0, 7), 'msg' => strtok((string)$x['commit']['message'], "\n"), 'by' => $x['commit']['author']['name'] ?? '', 'at' => $x['commit']['author']['date'] ?? ''], $r2) : [];
    wOut(200, ['connected' => true, 'repo' => $REPO, 'branch' => $BRANCH, 'root' => $ROOT, 'site' => $SITE, 'private' => !empty($r['private']), 'can_write' => !empty($r['permissions']['push']), 'commits' => $commits]);
}

if ($action === 'list') {
    $dir = $_GET['dir'] ?? '';
    if (!in_array($dir, ['projects', 'posts', 'pages'], true)) wOut(400, ['error' => 'Unknown folder']);
    [$c, $r] = gh('GET', "/repos/$REPO/git/trees/" . rawurlencode($BRANCH) . "?recursive=1", $TOKEN);
    if ($c !== 200) wOut(502, ['error' => "GitHub HTTP $c"]);
    $base = ($ROOT ? $ROOT . '/' : '') . $dir . '/';
    $out = [];
    foreach ($r['tree'] ?? [] as $t) {
        if ($t['type'] !== 'blob' || strpos($t['path'], $base) !== 0) continue;
        $rel = substr($t['path'], strlen($base));
        if ($dir === 'projects') {
            if (preg_match('#^([^/_][^/]*)/project\.json$#', $rel, $m)) $out[$m[1]]['json'] = $t['path'];
            elseif (preg_match('#^([^/_][^/]*)/images/(.+)$#', $rel, $m)) $out[$m[1]]['images'][] = $m[2];
        } elseif (preg_match('#^[^/]+\.json$#', $rel)) {
            $out[basename($rel, '.json')] = ['json' => $t['path']];
        }
    }
    wOut(200, ['items' => $out, 'truncated' => !empty($r['truncated'])]);
}

if ($action === 'get') {
    $p = safePath($_GET['path'] ?? '', $ROOT); if (!$p) wOut(400, ['error' => 'Path not allowed']);
    [$c, $r] = gh('GET', "/repos/$REPO/contents/" . $ghPath($p) . "?ref=" . rawurlencode($BRANCH), $TOKEN);
    if ($c !== 200) wOut($c === 404 ? 404 : 502, ['error' => "GitHub HTTP $c"]);
    $raw = base64_decode((string)($r['content'] ?? ''));
    $isText = (bool)preg_match('/\.(json|md|txt|html)$/i', $p);
    wOut(200, ['path' => $p, 'sha' => $r['sha'], 'content' => $isText ? $raw : null, 'base64' => $isText ? null : base64_encode($raw)]);
}

if (in_array($action, ['put', 'upload'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wOut(405, ['error' => 'POST only']);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $p = safePath($in['path'] ?? '', $ROOT); if (!$p) wOut(400, ['error' => 'Path not allowed']);
    if ($action === 'put') {
        if (!preg_match('/\.json$/i', $p)) wOut(400, ['error' => 'Only .json content files']);
        $content = (string)($in['content'] ?? '');
        if (json_decode($content) === null && json_last_error() !== JSON_ERROR_NONE) wOut(400, ['error' => 'That is not valid JSON — nothing was saved.']);
        $b64 = base64_encode($content);
    } else {
        if (!preg_match('/\.(webp|jpe?g|png|gif|svg|mp4)$/i', $p)) wOut(400, ['error' => 'Only images / mp4']);
        $b64 = preg_replace('#^data:[^;]+;base64,#', '', (string)($in['base64'] ?? ''));
        if (strlen($b64) > 14 * 1024 * 1024) wOut(400, ['error' => 'File too large (max ~10 MB)']);
    }
    $sha = $in['sha'] ?? null;
    if (!$sha) { [$c0, $r0] = gh('GET', "/repos/$REPO/contents/" . $ghPath($p) . "?ref=" . rawurlencode($BRANCH), $TOKEN); if ($c0 === 200) $sha = $r0['sha']; }
    $who = $s['email'] ?? 'admin';
    $body = ['message' => mb_substr(trim((string)($in['message'] ?? '')) ?: ("Update " . $p), 0, 200) . "\n\nFrom SocialFlow Website tab by " . $who, 'content' => $b64, 'branch' => $BRANCH];
    if ($sha) $body['sha'] = $sha;
    [$c, $r] = gh('PUT', "/repos/$REPO/contents/" . $ghPath($p), $TOKEN, $body);
    if ($c === 409) wOut(409, ['error' => 'Someone changed this file in the meantime — reload it and try again.']);
    if ($c !== 200 && $c !== 201) wOut(502, ['error' => "GitHub HTTP $c: " . ($r['message'] ?? '')]);
    try { $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), ?, 'website', ?, 'success', ?)")->execute(['website_' . $action, $p, $who]); } catch (Throwable $e) {}
    wOut(200, ['ok' => true, 'sha' => $r['content']['sha'] ?? null, 'commit' => substr($r['commit']['sha'] ?? '', 0, 7)]);
}

/* ─────────────────────────── Inbox: files for the website repo chat ───────────────────────────
 * Files go straight from the browser through this script to GitHub, onto a separate branch ("inbox").
 * Nothing is saved on this server, nothing is published, and the site build never sees them (it only builds main).   */
if (in_array($action, ['inbox_upload', 'inbox_list', 'inbox_delete', 'inbox_big', 'inbox_raw', 'inbox_thumb_put', 'inbox_folder_create', 'inbox_folder_delete', 'inbox_move'], true)) {
    if (!$REPO || !$TOKEN) wOut(200, ['connected' => false, 'error' => 'GitHub is not set up yet (see the Setup tab).']);
    $IB = 'inbox'; $who = $s['email'] ?? 'admin';
    $cleanFolder = function ($f) { $f = trim(preg_replace('/\s+/', '-', preg_replace('/[^A-Za-z0-9 _\-]+/u', '', (string)$f)), '-_ '); return mb_substr($f, 0, 50); };
    $ensureBranch = function () use ($REPO, $TOKEN, $IB, $BRANCH) {
        [$cr] = gh('GET', "/repos/$REPO/git/ref/heads/$IB", $TOKEN); if ($cr === 200) return null;
        if ($cr !== 404) return "GitHub HTTP $cr";
        [$cm, $rm] = gh('GET', "/repos/$REPO/git/ref/heads/$BRANCH", $TOKEN); if ($cm !== 200) return "Could not read the $BRANCH branch (HTTP $cm)";
        [$cc, $rc] = gh('POST', "/repos/$REPO/git/refs", $TOKEN, ['ref' => "refs/heads/$IB", 'sha' => $rm['object']['sha']]);
        return $cc === 201 ? null : "Could not create the inbox branch (HTTP $cc) — the token needs Contents: read and write. " . ($rc['message'] ?? '');
    };
    $ibUrl = fn($p) => "https://github.com/$REPO/blob/$IB/" . implode('/', array_map('rawurlencode', explode('/', $p)));
    // Small preview pictures live in one index file on the inbox branch: {file path or "release:ID": data-URI}.
    $thGet = function () use ($REPO, $TOKEN, $IB, $ghPath) {
        [$c, $r] = gh('GET', "/repos/$REPO/contents/inbox/_thumbs.json?ref=$IB", $TOKEN);
        if ($c !== 200) return [[], null];
        $raw = base64_decode((string)($r['content'] ?? ''));
        if ($raw === '' && !empty($r['sha'])) { [$cb, $rb] = gh('GET', "/repos/$REPO/git/blobs/" . $r['sha'], $TOKEN); if ($cb === 200) $raw = base64_decode((string)($rb['content'] ?? '')); }
        $j = json_decode($raw, true);
        return [is_array($j) ? $j : [], $r['sha'] ?? null];
    };
    $thPut = function (array $idx, $sha, $msg) use ($REPO, $TOKEN, $IB, $ghPath) {
        $body = ['message' => $msg, 'content' => base64_encode(json_encode($idx, JSON_UNESCAPED_SLASHES)), 'branch' => $IB]; if ($sha) $body['sha'] = $sha;
        [$c] = gh('PUT', "/repos/$REPO/contents/inbox/_thumbs.json", $TOKEN, $body);
        return $c === 200 || $c === 201;
    };
    if ($action === 'inbox_list') {
        [$thumbs] = $thGet();
        [$c, $r] = gh('GET', "/repos/$REPO/git/trees/$IB?recursive=1", $TOKEN);
        $items = [];
        $relL = null; [$cl, $rl] = gh('GET', "/repos/$REPO/releases/tags/inbox-files", $TOKEN);
        if ($cl === 200) foreach ($rl['assets'] ?? [] as $a) $items[] = ['path' => 'release:' . $a['id'], 'name' => (strpos($a['name'], '~') !== false ? explode('~', $a['name'], 2)[1] : $a['name']), 'asset' => $a['name'], 'size' => (int)$a['size'], 'url' => $rl['html_url'], 'big' => true, 'folder' => (strpos($a['name'], '~') !== false ? explode('~', $a['name'], 2)[0] : substr((string)$a['created_at'], 0, 10)), 'date' => substr((string)$a['created_at'], 0, 10), 'added' => (string)$a['created_at'], 'cmd' => 'gh release download inbox-files -R ' . $REPO . ' -p "' . $a['name'] . '"'];
        if ($c === 404) wOut(200, ['items' => $items, 'repo' => $REPO, 'thumbs' => (object)$thumbs, 'folders' => array_values(array_unique(array_column($items, 'folder')))]);
        if ($c !== 200) wOut(502, ['error' => "GitHub HTTP $c"]);
        $when = [];
        [$cc, $rcm] = gh('GET', "/repos/$REPO/commits?sha=$IB&per_page=100", $TOKEN);
        if ($cc === 200) foreach ($rcm as $cm) { $first = strtok((string)($cm['commit']['message'] ?? ''), "\n"); if (preg_match('/^Inbox: (.+)$/', $first, $mm) && !preg_match('/^(move |new folder|remove )/i', $mm[1])) $when[$mm[1]] = $cm['commit']['author']['date'] ?? ''; }
        $folders = [];
        foreach ($r['tree'] ?? [] as $t) if ($t['type'] === 'blob' && strpos($t['path'], 'inbox/') === 0 && $t['path'] !== 'inbox/_thumbs.json') {
            $seg = explode('/', $t['path']); $fo = $seg[1] ?? ''; if ($fo !== '') $folders[$fo] = true;
            if (basename($t['path']) === '.gitkeep') continue;
            $items[] = ['path' => $t['path'], 'name' => basename($t['path']), 'size' => (int)($t['size'] ?? 0), 'url' => $ibUrl($t['path']), 'folder' => $fo, 'date' => $fo, 'added' => $when[basename($t['path'])] ?? ''];
        }
        foreach ($items as $it) if (!empty($it['folder'])) $folders[$it['folder']] = true;
        usort($items, fn($a, $b) => strcmp($b['folder'] . $b['name'], $a['folder'] . $a['name']));
        wOut(200, ['items' => array_slice($items, 0, 300), 'repo' => $REPO, 'branch' => $IB, 'thumbs' => (object)$thumbs, 'folders' => array_values(array_keys($folders))]);
    }
    // ── Big files (22–200 MB): streamed to a GitHub *release asset* (tag "inbox-files"). Git itself can't hold files over 100 MB. ──
    $RT = 'inbox-files';
    $relGet = function ($create) use ($REPO, $TOKEN, $RT, $BRANCH) {
        [$c, $r] = gh('GET', "/repos/$REPO/releases/tags/$RT", $TOKEN);
        if ($c === 200) return $r;
        if (!$create) return null;
        [$c2, $r2] = gh('POST', "/repos/$REPO/releases", $TOKEN, ['tag_name' => $RT, 'target_commitish' => $BRANCH, 'name' => 'Inbox files', 'body' => 'Large files uploaded from the SocialFlow Website tab for the website chat. Not part of the site.', 'prerelease' => true, 'draft' => false]);
        return $c2 === 201 ? $r2 : null;
    };
    if ($action === 'inbox_big') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wOut(405, ['error' => 'POST only']);
        @set_time_limit(1800); @ignore_user_abort(true);
        $name = preg_replace('/[^A-Za-z0-9._\- ]+/u', '-', basename((string)($_GET['name'] ?? '')));
        $name = trim(preg_replace('/\s+/', '-', $name), '.-');
        if ($name === '' || !preg_match('/\.(jpe?g|png|webp|gif|svg|mp4|mov|webm|pdf|docx?|xlsx?|pptx?|txt|csv|json|zip|md)$/i', $name)) wOut(400, ['error' => 'This file type is not allowed here.']);
        $fo = $cleanFolder($_GET['folder'] ?? ''); if ($fo !== '') $name = $fo . '~' . $name;
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len <= 0) wOut(400, ['error' => 'The server received no data — its PHP upload limit (post_max_size) is probably lower than this file. Ask for it to be raised to 210M.']);
        if ($len > 200 * 1024 * 1024) wOut(400, ['error' => 'File too large (max 200 MB).']);
        $rel = $relGet(true); if (!$rel) wOut(502, ['error' => 'Could not create the "inbox-files" release — the GitHub token needs Contents: read and write.']);
        foreach ($rel['assets'] ?? [] as $a) if ($a['name'] === $name) $name = preg_replace('/(\.[^.]+)$/', '-' . date('His') . '$1', $name);
        $in = fopen('php://input', 'r');
        $ch = curl_init("https://uploads.github.com/repos/$REPO/releases/" . (int)$rel['id'] . "/assets?name=" . rawurlencode($name));
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILESIZE => $len, CURLOPT_READFUNCTION => function ($c, $fd, $n) use ($in) { return (string)fread($in, $n); },
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1700, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $TOKEN, 'Accept: application/vnd.github+json', 'User-Agent: SocialFlow-Website-Tab', 'Content-Type: ' . (preg_match('/^[\w.+-]+\/[\w.+-]+$/', (string)($_SERVER['CONTENT_TYPE'] ?? '')) ? $_SERVER['CONTENT_TYPE'] : 'application/octet-stream'), 'Expect:']]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch); fclose($in);
        $j = json_decode((string)$res, true);
        if ($code !== 201) wOut(502, ['error' => 'GitHub upload failed' . ($cerr ? ": $cerr" : " (HTTP $code)") . (isset($j['message']) ? ' — ' . $j['message'] : '')]);
        try { $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'website_inbox_upload', 'website', ?, 'success', ?)")->execute(['release:' . $name, $who]); } catch (Throwable $e) {}
        wOut(200, ['ok' => true, 'path' => 'release:' . $j['id'], 'name' => (strpos($j['name'], '~') !== false ? explode('~', $j['name'], 2)[1] : $j['name']), 'size' => (int)$j['size'], 'cmd' => 'gh release download ' . $RT . ' -R ' . $REPO . ' -p "' . $j['name'] . '"']);
    }
    // Streams one inbox file to the browser (used to build previews). Passes straight through; nothing is stored here.
    if ($action === 'inbox_raw') {
        @set_time_limit(300);
        $aid = (int)($_GET['asset'] ?? 0); $p = trim((string)($_GET['path'] ?? ''), '/');
        if ($aid) { [$cm, $rm] = gh('GET', "/repos/$REPO/releases/assets/$aid", $TOKEN); if ($cm !== 200) wOut(404, ['error' => 'Not found']); $size = (int)$rm['size']; $nm = $rm['name']; $url = "https://api.github.com/repos/$REPO/releases/assets/$aid"; $accept = 'application/octet-stream'; }
        else {
            if (strpos($p, 'inbox/') !== 0 || strpos($p, '..') !== false || $p === 'inbox/_thumbs.json') wOut(400, ['error' => 'Path not allowed']);
            [$cm, $rm] = gh('GET', "/repos/$REPO/contents/" . $ghPath($p) . "?ref=$IB", $TOKEN); if ($cm !== 200) wOut(404, ['error' => 'Not found']);
            $size = (int)$rm['size']; $nm = basename($p); $url = "https://api.github.com/repos/$REPO/contents/" . $ghPath($p) . "?ref=$IB"; $accept = 'application/vnd.github.raw';
        }
        if ($size > 60 * 1024 * 1024) wOut(413, ['error' => 'Too large for a preview']);
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'pdf' => 'application/pdf'];
        $ct = $types[strtolower(pathinfo($nm, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        header_remove('Content-Type'); header('Content-Type: ' . $ct); header('Content-Length: ' . $size); header('Cache-Control: private, max-age=600'); header('Content-Security-Policy: sandbox'); header('X-Content-Type-Options: nosniff');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 280, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $TOKEN, 'Accept: ' . $accept, 'User-Agent: SocialFlow-Website-Tab'],
            CURLOPT_WRITEFUNCTION => function ($c, $d) { echo $d; return strlen($d); }]);
        curl_exec($ch); curl_close($ch); exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if ($action === 'inbox_folder_create') {
        $fo = $cleanFolder($in['name'] ?? ''); if ($fo === '' || $fo === '_thumbs') wOut(400, ['error' => 'Give the folder a name (letters, numbers, spaces, - and _).']);
        if ($e = $ensureBranch()) wOut(502, ['error' => $e]);
        [$c, $r] = gh('PUT', "/repos/$REPO/contents/inbox/" . rawurlencode($fo) . "/.gitkeep", $TOKEN, ['message' => "Inbox: new folder $fo", 'content' => base64_encode("folder\n"), 'branch' => $IB]);
        if ($c !== 200 && $c !== 201) wOut(502, ['error' => "GitHub HTTP $c: " . ($r['message'] ?? '')]);
        wOut(200, ['ok' => true, 'folder' => $fo]);
    }
    if ($action === 'inbox_folder_delete') {          // only an EMPTY folder (just its placeholder)
        $fo = $cleanFolder($in['name'] ?? ''); if ($fo === '') wOut(400, ['error' => 'Bad folder']);
        [$ct, $rt] = gh('GET', "/repos/$REPO/git/trees/$IB?recursive=1", $TOKEN); if ($ct !== 200) wOut(502, ['error' => "GitHub HTTP $ct"]);
        foreach ($rt['tree'] ?? [] as $t) if ($t['type'] === 'blob' && strpos($t['path'], "inbox/$fo/") === 0 && basename($t['path']) !== '.gitkeep') wOut(400, ['error' => 'The folder is not empty — move or delete its files first.']);
        [$cg, $rg] = gh('GET', "/repos/$REPO/contents/inbox/" . rawurlencode($fo) . "/.gitkeep?ref=$IB", $TOKEN);
        if ($cg === 200) gh('DELETE', "/repos/$REPO/contents/inbox/" . rawurlencode($fo) . "/.gitkeep", $TOKEN, ['message' => "Inbox: remove folder $fo", 'sha' => $rg['sha'], 'branch' => $IB]);
        wOut(200, ['ok' => true]);
    }
    if ($action === 'inbox_move') {
        $fo = $cleanFolder($in['folder'] ?? ''); $src = trim((string)($in['path'] ?? ''), '/'); if ($fo === '') wOut(400, ['error' => 'Choose a folder']);
        if (strpos($src, 'release:') === 0) {              // big file: just rename the release file ("folder~name")
            $aid = (int)substr($src, 8); [$cm, $rm] = gh('GET', "/repos/$REPO/releases/assets/$aid", $TOKEN); if ($cm !== 200) wOut(404, ['error' => 'File not found']);
            $base = strpos($rm['name'], '~') !== false ? explode('~', $rm['name'], 2)[1] : $rm['name'];
            [$cp, $rp] = gh('PATCH', "/repos/$REPO/releases/assets/$aid", $TOKEN, ['name' => "$fo~$base"]); if ($cp !== 200) wOut(502, ['error' => "GitHub HTTP $cp: " . ($rp['message'] ?? '')]);
            wOut(200, ['ok' => true, 'path' => $src]);
        }
        if (strpos($src, 'inbox/') !== 0 || strpos($src, '..') !== false) wOut(400, ['error' => 'Path not allowed']);
        $dst = "inbox/$fo/" . basename($src); if ($dst === $src) wOut(200, ['ok' => true, 'path' => $src]);
        [$c0, $r0] = gh('GET', "/repos/$REPO/contents/" . $ghPath($src) . "?ref=$IB", $TOKEN); if ($c0 !== 200) wOut(404, ['error' => 'File not found']);
        $content = $r0['content'] ?? '';
        if ($content === '' || !empty($r0['truncated'])) { [$cb, $rb] = gh('GET', "/repos/$REPO/git/blobs/" . $r0['sha'], $TOKEN); if ($cb === 200) $content = $rb['content']; }
        $content = preg_replace('/\s+/', '', (string)$content); if ($content === '') wOut(502, ['error' => 'Could not read the file']);
        [$ce] = gh('GET', "/repos/$REPO/contents/" . $ghPath($dst) . "?ref=$IB", $TOKEN); if ($ce === 200) $dst = preg_replace('/(\.[^.]+)$/', '-' . date('His') . '$1', $dst);
        [$cp, $rp] = gh('PUT', "/repos/$REPO/contents/" . $ghPath($dst), $TOKEN, ['message' => 'Inbox: move ' . basename($src) . " to $fo", 'content' => $content, 'branch' => $IB]); if ($cp !== 200 && $cp !== 201) wOut(502, ['error' => "GitHub HTTP $cp: " . ($rp['message'] ?? '')]);
        gh('DELETE', "/repos/$REPO/contents/" . $ghPath($src), $TOKEN, ['message' => 'Inbox: moved ' . basename($src), 'sha' => $r0['sha'], 'branch' => $IB]);
        [$ix, $sh] = $thGet(); if (isset($ix[$src])) { $ix[$dst] = $ix[$src]; unset($ix[$src]); $thPut($ix, $sh, 'Move preview'); }
        wOut(200, ['ok' => true, 'path' => $dst]);
    }
    if ($action === 'inbox_thumb_put') {
        $key = (string)($in['path'] ?? ''); $data = (string)($in['data'] ?? '');
        if (!preg_match('#^(inbox/[^\s]+|release:\d+)$#', $key) || strpos($key, '..') !== false) wOut(400, ['error' => 'Bad key']);
        if (!preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $data) || strlen($data) > 60000) wOut(400, ['error' => 'Bad preview']);
        [$idx, $sha] = $thGet(); $idx[$key] = $data;
        if (!$thPut($idx, $sha, 'Inbox preview')) wOut(502, ['error' => 'Could not save the preview']);
        wOut(200, ['ok' => true]);
    }
    if ($action === 'inbox_delete' && strpos((string)($in['path'] ?? ''), 'release:') === 0) {
        $aid = (int)substr((string)$in['path'], 8);
        [$c] = gh('DELETE', "/repos/$REPO/releases/assets/$aid", $TOKEN);
        if ($c !== 204) wOut(502, ['error' => "GitHub HTTP $c"]);
        [$ix, $sh] = $thGet(); if (isset($ix[$in['path']])) { unset($ix[$in['path']]); $thPut($ix, $sh, 'Remove preview'); }
        wOut(200, ['ok' => true]);
    }
    if ($action === 'inbox_delete') {
        $p = trim((string)($in['path'] ?? ''), '/');
        if (strpos($p, 'inbox/') !== 0 || strpos($p, '..') !== false) wOut(400, ['error' => 'Path not allowed']);
        [$c0, $r0] = gh('GET', "/repos/$REPO/contents/" . $ghPath($p) . "?ref=$IB", $TOKEN);
        if ($c0 !== 200) wOut(404, ['error' => 'File not found']);
        [$c, $r] = gh('DELETE', "/repos/$REPO/contents/" . $ghPath($p), $TOKEN, ['message' => 'Remove ' . basename($p) . ' from inbox', 'sha' => $r0['sha'], 'branch' => $IB]);
        if ($c !== 200) wOut(502, ['error' => "GitHub HTTP $c"]);
        [$ix, $sh] = $thGet(); if (isset($ix[$p])) { unset($ix[$p]); $thPut($ix, $sh, 'Remove preview'); }
        wOut(200, ['ok' => true]);
    }
    // upload
    $name = preg_replace('/[^A-Za-z0-9._\- ]+/u', '-', basename((string)($in['name'] ?? '')));
    $name = trim(preg_replace('/\s+/', '-', $name), '.-');
    if ($name === '' || !preg_match('/\.(jpe?g|png|webp|gif|svg|mp4|mov|webm|pdf|docx?|xlsx?|pptx?|txt|csv|json|zip|md)$/i', $name)) wOut(400, ['error' => 'This file type is not allowed here (images, video, PDF, Office files, text and zip are).']);
    $b64 = preg_replace('#^data:[^;]*;base64,#', '', (string)($in['base64'] ?? ''));
    if ($b64 === '') wOut(400, ['error' => 'Empty file']);
    if (strlen($b64) > 30 * 1024 * 1024) wOut(400, ['error' => 'File too large for this route (use the big-file route above 22 MB).']);
    if ($e = $ensureBranch()) wOut(502, ['error' => $e]);
    $fo = $cleanFolder($in['folder'] ?? '');
    $path = 'inbox/' . ($fo !== '' ? $fo : (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d')) . '/' . $name;
    [$ce] = gh('GET', "/repos/$REPO/contents/" . $ghPath($path) . "?ref=$IB", $TOKEN);
    if ($ce === 200) $path = preg_replace('/(\.[^.]+)$/', '-' . date('His') . '$1', $path);
    [$c, $r] = gh('PUT', "/repos/$REPO/contents/" . $ghPath($path), $TOKEN, ['message' => "Inbox: $name\n\nUploaded from the SocialFlow Website tab by $who", 'content' => $b64, 'branch' => $IB]);
    if ($c !== 200 && $c !== 201) wOut(502, ['error' => "GitHub HTTP $c: " . ($r['message'] ?? '')]);
    try { $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'website_inbox_upload', 'website', ?, 'success', ?)")->execute([$path, $who]); } catch (Throwable $e) {}
    wOut(200, ['ok' => true, 'path' => $path, 'url' => $ibUrl($path), 'size' => (int)($r['content']['size'] ?? 0)]);
}

wOut(400, ['error' => 'Unknown action']);
