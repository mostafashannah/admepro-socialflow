<?php
/**
 * seo-agent.php — the SEO Growth agent (admin only).
 *
 *   HTTP (Website → SEO agent tab):  overview · settings · run · job_status · list · save_changes · approve · reject · revert · done
 *   CLI  (cron, weekly):             php seo-agent.php cron          (analyse + propose; auto-applies safe fixes only when allowed)
 *                                    php seo-agent.php run [email]   (one analysis run)
 *                                    php seo-agent.php measure       (check results of changes applied 3+ weeks ago)
 *
 * Safety: it can only change TEXT inside the website's content JSON files (existing string values), or add NEW posts as
 * unpublished drafts. It never touches code/design, never deletes, every change is its own commit (revertable), and
 * nothing is published until an admin approves it (or the admin turned on auto-apply for safe title/description fixes).
 * Needs: Search Console connected (GSC_KEY_FILE/GSC_SITE) and the website GitHub settings from the Website tab.
 */
require_once __DIR__ . '/growth-lib.php';

const SEO_JOB_PREFIX = 'sf-seo-job-';
const SEO_GCC = ['EGY', 'SAU', 'ARE', 'KWT', 'QAT', 'BHR', 'OMN'];

function seoJobFile($id) { return sys_get_temp_dir() . '/' . SEO_JOB_PREFIX . preg_replace('/[^a-f0-9]/', '', $id) . '.json'; }
function seoSetting(PDO $pdo, $k, $d = null) { try { $st = $pdo->prepare("SELECT v FROM seo_settings WHERE k=?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : $v; } catch (Throwable $e) { return $d; } }
function seoSave(PDO $pdo, $k, $v) { $pdo->prepare("INSERT INTO seo_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, (string)$v]); }
function seoCfg(PDO $pdo) {
    return [
        'enabled' => seoSetting($pdo, 'enabled', '1') === '1',
        'auto_from' => seoSetting($pdo, 'auto_from', ''),                 // YYYY-MM-DD: from this date safe fixes apply by themselves
        'markets' => array_values(array_filter(explode(',', seoSetting($pdo, 'markets', 'EG,SA,AE,KW,QA')))),
        'per_run' => max(3, min(15, (int)seoSetting($pdo, 'per_run', '8'))),
        'notes' => seoSetting($pdo, 'notes', ''),
    ];
}
function seoAgentModel(PDO $pdo) {
    $cfg = []; try { $v = $pdo->query("SELECT ai_agents FROM app_settings LIMIT 1")->fetchColumn(); $j = json_decode((string)$v, true); $cfg = $j['seo_agent'] ?? []; } catch (Throwable $e) {}
    $m = trim($cfg['model'] ?? ''); $f = trim($cfg['fallback_model'] ?? '');
    $ok = fn($x) => $x !== '' && stripos($x, 'gpt') === false;     // this agent runs on Claude models
    return ['model' => $ok($m) ? $m : 'claude-sonnet-5-5', 'fallback' => $ok($f) ? $f : '', 'skills' => trim($cfg['skills'] ?? '')];
}
function seoLog(PDO $pdo, $action, $detail, $status = 'success', $err = null, $start = null) {
    try {
        $end = gmdate('c'); $timing = $start ? '|start:' . gmdate('c', $start) . '|end:' . $end : '';
        $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by, error_message) VALUES (UUID(), ?, 'agents', ?, ?, 'agent', ?)")
            ->execute(['SEO agent: ' . $action, '[agent:seo_agent] ' . $detail . $timing, $status, $err]);
    } catch (Throwable $e) { error_log('[seo] log: ' . $e->getMessage()); }
}

/* ───────── GitHub (website repo) ───────── */
function seoRepo() { return [defined('WEBSITE_GITHUB_REPO') ? WEBSITE_GITHUB_REPO : '', defined('WEBSITE_GITHUB_TOKEN') ? WEBSITE_GITHUB_TOKEN : '',
    defined('WEBSITE_GITHUB_BRANCH') && WEBSITE_GITHUB_BRANCH ? WEBSITE_GITHUB_BRANCH : 'main', trim(defined('WEBSITE_CONTENT_ROOT') ? WEBSITE_CONTENT_ROOT : 'src/content', '/'),
    defined('WEBSITE_URL') && WEBSITE_URL ? rtrim(WEBSITE_URL, '/') : 'https://admepro.com']; }
function seoGh($method, $url, $body = null) {
    [, $token] = seoRepo();
    $ch = curl_init('https://api.github.com' . $url);
    $h = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: SocialFlow-SEO-Agent'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$res, true)];
}
function seoGhPath($p) { return implode('/', array_map('rawurlencode', explode('/', $p))); }
/** Allowed files: content JSON inside the content root only. */
function seoSafePath($p, $root) {
    $p = trim(str_replace('\\', '/', (string)$p), '/');
    if ($p === '' || strpos($p, '..') !== false || preg_match('#(^|/)\.#', $p) || !preg_match('/\.json$/i', $p)) return null;
    return ($root === '' || strpos($p . '/', $root . '/') === 0) ? $p : null;
}
function seoReadFile($path) {
    [$repo, , $branch] = seoRepo();
    [$c, $r] = seoGh('GET', "/repos/$repo/contents/" . seoGhPath($path) . '?ref=' . rawurlencode($branch));
    if ($c !== 200) return null;
    return ['sha' => $r['sha'], 'content' => base64_decode((string)$r['content'])];
}
function seoWriteFile($path, $content, $sha, $msg) {
    [$repo, , $branch] = seoRepo();
    $body = ['message' => mb_substr($msg, 0, 200) . "\n\nFrom the SocialFlow SEO agent", 'content' => base64_encode($content), 'branch' => $branch];
    if ($sha) $body['sha'] = $sha;
    [$c, $r] = seoGh('PUT', "/repos/$repo/contents/" . seoGhPath($path), $body);
    return [$c === 200 || $c === 201, substr($r['commit']['sha'] ?? '', 0, 7), $c === 409 ? 'The file changed on GitHub meanwhile' : ($c >= 300 ? "GitHub HTTP $c " . ($r['message'] ?? '') : null)];
}
function seoListContent() {
    [$repo, , $branch, $root] = seoRepo();
    [$c, $r] = seoGh('GET', "/repos/$repo/git/trees/" . rawurlencode($branch) . '?recursive=1');
    if ($c !== 200) return null;
    $out = [];
    foreach ($r['tree'] ?? [] as $t) if ($t['type'] === 'blob' && preg_match('/\.json$/', $t['path']) && ($root === '' || strpos($t['path'], $root . '/') === 0)) $out[] = $t['path'];
    return $out;
}

/* ───────── dotted-path helpers on decoded JSON ───────── */
function seoGetPath($data, $field) { foreach (explode('.', $field) as $k) { if (!is_array($data) || !array_key_exists($k, $data)) return [false, null]; $data = $data[$k]; } return [true, $data]; }
function seoSetPath(&$data, $field, $val) { $ref = &$data; foreach (explode('.', $field) as $k) { if (!is_array($ref) || !array_key_exists($k, $ref)) return false; $ref = &$ref[$k]; } $ref = $val; return true; }
function seoIsMetaField($f) { return (bool)preg_match('/^(seo\.(title|description)(\.(en|ar))?|seoTitle|description)$/', $f); }
function seoCleanText($s) { $s = trim((string)$s); return (stripos($s, '<script') !== false || stripos($s, 'javascript:') !== false) ? null : $s; }

/* ───────── Search Console ───────── */
function seoGscToken() {
    $cache = sys_get_temp_dir() . '/sf-gsc-token.json';
    $c = json_decode((string)@file_get_contents($cache), true);
    if (!empty($c['t']) && $c['exp'] > time() + 60) return $c['t'];
    if (!defined('GSC_KEY_FILE') || !is_readable(GSC_KEY_FILE)) return null;
    $key = json_decode((string)file_get_contents(GSC_KEY_FILE), true);
    if (empty($key['private_key'])) return null;
    $b64 = fn($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $now = time();
    $u = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($u, $sig, $key['private_key'], 'sha256WithRSAEncryption')) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $u . '.' . $b64($sig)])]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (empty($j['access_token'])) return null;
    @file_put_contents($cache, json_encode(['t' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)])); @chmod($cache, 0600);
    return $j['access_token'];
}
function seoGsc(array $body) {
    $tok = seoGscToken(); if (!$tok) return null;
    $site = defined('GSC_SITE') && GSC_SITE ? GSC_SITE : 'sc-domain:admepro.com';
    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return isset($j['error']) ? null : ($j['rows'] ?? []);
}

/* ───────── gather everything the agent should look at ───────── */
function seoGather(PDO $pdo, array $cfg) {
    $end = (new DateTime('now'))->modify('-2 days'); $start = (clone $end)->modify('-27 days'); $S = $start->format('Y-m-d'); $E = $end->format('Y-m-d');
    $ctx = ['period' => "$S → $E"];
    $qp = seoGsc(['startDate' => $S, 'endDate' => $E, 'dimensions' => ['query', 'page'], 'rowLimit' => 400]);
    if ($qp === null) return [null, 'Search Console is not connected (see Website → Setup).'];
    $ctx['search'] = array_map(fn($r) => [$r['keys'][0], $r['keys'][1], (int)$r['clicks'], (int)$r['impressions'], round($r['position'], 1)], $qp);
    $gcc = seoGsc(['startDate' => $S, 'endDate' => $E, 'dimensions' => ['query', 'country'], 'rowLimit' => 150,
        'dimensionFilterGroups' => [['filters' => [['dimension' => 'country', 'operator' => 'includingRegex', 'expression' => implode('|', array_map('strtolower', SEO_GCC))]]]]]);
    $ctx['search_by_country'] = array_map(fn($r) => [$r['keys'][0], strtoupper($r['keys'][1]), (int)$r['clicks'], (int)$r['impressions'], round($r['position'], 1)], $gcc ?: []);
    $cty = seoGsc(['startDate' => $S, 'endDate' => $E, 'dimensions' => ['country'], 'rowLimit' => 15]);
    $ctx['countries'] = array_map(fn($r) => [strtoupper($r['keys'][0]), (int)$r['clicks'], (int)$r['impressions']], $cty ?: []);
    // visitor data (own tracker)
    try {
        $q = fn($sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        $ctx['pages_traffic'] = $q("SELECT path, COUNT(*) views, COUNT(DISTINCT visitor) visitors, ROUND(COALESCE(AVG(NULLIF(secs,0)),0)) FROM site_events WHERE event='pageview' AND day >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) GROUP BY path ORDER BY views DESC LIMIT 25");
        $ctx['sources'] = $q("SELECT COALESCE(utm_source, ref_host, 'Direct'), COUNT(DISTINCT visitor) FROM site_events WHERE event='pageview' AND day >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) GROUP BY 1 ORDER BY 2 DESC LIMIT 10");
        $ctx['visitor_countries'] = $q("SELECT COALESCE(country,'??'), COUNT(DISTINCT visitor) FROM site_events WHERE event='pageview' AND day >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) GROUP BY 1 ORDER BY 2 DESC LIMIT 10");
        $ctx['contact_clicks'] = (int)$pdo->query("SELECT COUNT(*) FROM site_events WHERE event='click' AND day >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)")->fetchColumn();
        $ctx['website_leads'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE source='website' AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)")->fetchColumn();
    } catch (Throwable $e) { $ctx['visitor_data'] = 'unavailable'; }
    // website content
    $files = seoListContent();
    if ($files === null) return [null, 'Could not read the website repo (check the GitHub settings in Website → Setup).'];
    $ctx['content_files'] = $files;
    $docs = [];
    foreach (array_slice(array_filter($files, fn($f) => preg_match('#/(pages/[^/]+|posts/[^/]+|projects/[^/]+/project)\.json$#', $f)), 0, 70) as $f) {
        $r = seoReadFile($f); if (!$r) continue;
        $j = json_decode($r['content'], true); if (!is_array($j)) continue;
        if (isset($j['bodyHtml'])) $j['bodyHtml'] = mb_substr(strip_tags((string)$j['bodyHtml']), 0, 700) . '…';
        $docs[$f] = $j;
    }
    $ctx['content'] = $docs;
    return [$ctx, null];
}

/* ───────── the analysis run ───────── */
function seoRun(PDO $pdo, $by, $jobFile = null) {
    $cfg = seoCfg($pdo); $t0 = time();
    $pdo->prepare("INSERT INTO seo_runs (started_at, trigger_by) VALUES (?, ?)")->execute([gNow(), $by]); $runId = (int)$pdo->lastInsertId();
    $step = function ($s) use ($jobFile) { if ($jobFile) { $j = json_decode((string)@file_get_contents($jobFile), true) ?: []; $j['step'] = $s; @file_put_contents($jobFile, json_encode($j)); } };
    $fail = function ($msg) use ($pdo, $runId, $t0) { $pdo->prepare("UPDATE seo_runs SET status='error', finished_at=?, error=? WHERE id=?")->execute([gNow(), mb_substr($msg, 0, 480), $runId]); seoLog($pdo, 'run failed', $msg, 'error', $msg, $t0); return [false, $msg]; };

    $step('Reading Search Console, visitor data and the website…');
    [$ctx, $err] = seoGather($pdo, $cfg); if (!$ctx) return $fail($err);
    // what's already pending/applied, so it doesn't repeat itself
    $prior = $pdo->query("SELECT type, title, status FROM seo_suggestions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_NUM);

    $m = seoAgentModel($pdo);
    $markets = implode(', ', $cfg['markets']);
    $prompt = "You are the SEO & growth agent for admepro, an AI-powered marketing, branding, content and production agency based in New Cairo, Egypt, winning clients in Egypt and the GCC. The site has English and Arabic versions.\n"
        . "Goal: more qualified clients from search in these markets: $markets.\n\n"
        . "DATA (last 28 days, $ctx[period]):\n" . json_encode(array_diff_key($ctx, ['content' => 1, 'content_files' => 1]), JSON_UNESCAPED_UNICODE) . "\n\n"
        . "SEARCH rows are [query, page, clicks, impressions, avg position]. search_by_country rows are [query, country, clicks, impressions, position].\n\n"
        . "WEBSITE CONTENT (editable JSON files; body text truncated):\n" . json_encode($ctx['content'], JSON_UNESCAPED_UNICODE) . "\n\n"
        . "ALL CONTENT FILE PATHS you may change or create: " . json_encode($ctx['content_files']) . "\n\n"
        . "ALREADY PROPOSED in the last 90 days (do not repeat): " . json_encode($prior, JSON_UNESCAPED_UNICODE) . "\n"
        . ($cfg['notes'] ? "OWNER'S NOTES: " . $cfg['notes'] . "\n" : '')
        . ($m['skills'] ? "SKILLS & GUIDES set by the admin (always follow): " . $m['skills'] . "\n" : '')
        . "\nUse web search to check who ranks for the most promising keywords in Egypt and the GCC (English and Arabic) and what they cover, and to verify facts you rely on. Do not invent statistics, awards or clients.\n\n"
        . "Return the {$cfg['per_run']} highest-impact suggestions, best first, as ONE JSON array and nothing else. Each item:\n"
        . "{\"type\":\"meta|content|technical|conversion|offsite\",\"priority\":1-100,\"title\":\"short action title\",\"why\":\"2-3 sentences citing the actual numbers\",\n"
        . " \"evidence\":{\"query\":\"main search\",\"page\":\"url or path\",\"impressions\":0,\"clicks\":0,\"position\":0,\"markets\":[\"EG\",\"SA\"]},\n"
        . " \"changes\":[ {\"path\":\"<an exact path from the list>\",\"op\":\"set\",\"field\":\"dotted.path.to.an.EXISTING.string\",\"after\":\"new text\"} ,\n"
        . "              {\"path\":\"<content root>/posts/<new-slug>.json\",\"op\":\"create_post\",\"json\":{\"slug\":\"\",\"date\":\"YYYY-MM-DD\",\"title\":\"\",\"seoTitle\":\"≤60 chars\",\"description\":\"140-155 chars\",\"lang\":\"en|ar\",\"cover\":\"\",\"bodyHtml\":\"<h2>…</h2><p>…</p>\"}} ],\n"
        . " \"manual_steps\":[\"things only a person can do, e.g. Google Business Profile, reviews, directories, backlinks\"]}\n\n"
        . "Rules:\n"
        . "- meta: improve the title (≤60 chars) and description (140-155 chars) of pages that rank 4-30 or have many impressions and low clicks, matching the real query wording. Edit en and ar separately (field e.g. seo.title.en, seo.description.ar). Only 'set' on fields that already exist in the file as strings.\n"
        . "- content: new posts are ALWAYS drafts (the system forces published=false). 900-1500 words of genuinely useful, specific content, structured with h2/h3, internal links to existing pages, a clear call to action. For Gulf markets write natural Gulf-friendly Arabic, not a literal translation. One topic per post; never thin or duplicate content.\n"
        . "- conversion: pages with traffic but weak engagement or no contact clicks: propose text changes to existing string fields (headline, CTA wording).\n"
        . "- technical / offsite: no file changes needed unless a text field fits; put the work in manual_steps with concrete, ordered instructions.\n"
        . "- Never touch code, design, URLs or slugs of existing pages. Keep brand tone: confident, clear, no hype.\n"
        . "- If the data is still thin (few clicks), say so honestly in 'why' and favour content and technical foundations over micro-tuning.";

    $req = ['model' => $m['model'], 'max_tokens' => 16000, 'output_config' => ['effort' => 'medium'], 'messages' => [['role' => 'user', 'content' => $prompt]]];
    $haiku = stripos($m['model'], 'haiku') !== false;
    $req['tools'] = $haiku ? [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 8]]
        : [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 20], ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 10]];
    if (preg_match('/(opus|sonnet)-5|fable-5/', $m['model'])) $req['thinking'] = ['type' => 'adaptive'];
    if ($haiku) unset($req['output_config']);

    $step('Researching Egypt & GCC results and writing suggestions… (this takes a few minutes)');
    [$ok, $text, , $e] = gClaude($req, 900);
    if (!$ok && $m['fallback']) { $req['model'] = $m['fallback']; unset($req['thinking']); [$ok, $text, , $e] = gClaude($req, 900); }
    if (!$ok) return $fail($e ?: 'The AI request failed');
    $list = gJson($text);
    if (!is_array($list) || !array_is_list($list)) return $fail('The agent did not return a usable list — try again.');

    $step('Checking every change against the real files…');
    [, , , $root] = seoRepo();
    $added = 0;
    foreach (array_slice($list, 0, 20) as $s) {
        if (!is_array($s) || empty($s['title'])) continue;
        $type = in_array($s['type'] ?? '', ['meta', 'content', 'technical', 'conversion', 'offsite'], true) ? $s['type'] : 'technical';
        $changes = [];
        foreach ((array)($s['changes'] ?? []) as $ch) {
            if (!is_array($ch)) continue;
            $path = seoSafePath($ch['path'] ?? '', $root); if (!$path) continue;
            if (($ch['op'] ?? '') === 'create_post') {
                if (!preg_match('#/posts/[a-z0-9\-]+\.json$#', $path) || in_array($path, $ctx['content_files'], true)) continue;
                $j = (array)($ch['json'] ?? []); if (empty($j['title']) || empty($j['bodyHtml'])) continue;
                foreach (['title', 'seoTitle', 'description', 'bodyHtml'] as $k) if (isset($j[$k]) && seoCleanText($j[$k]) === null) continue 2;
                $j['slug'] = basename($path, '.json'); $j['published'] = false; $j['date'] = $j['date'] ?? date('Y-m-d'); $j['cover'] = $j['cover'] ?? ''; $j['lang'] = ($j['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
                $changes[] = ['path' => $path, 'op' => 'create_post', 'json' => $j];
            } elseif (($ch['op'] ?? '') === 'set' && in_array($path, $ctx['content_files'], true)) {
                $field = (string)($ch['field'] ?? ''); $after = seoCleanText($ch['after'] ?? ''); if ($field === '' || $after === null || $after === '') continue;
                $cur = $ctx['content'][$path] ?? null; if (!$cur) continue;
                [$has, $before] = seoGetPath($cur, $field); if (!$has || !is_string($before) || $field === 'bodyHtml') continue;
                $changes[] = ['path' => $path, 'op' => 'set', 'field' => $field, 'before' => $before, 'after' => $after];
            }
        }
        if (in_array($type, ['meta', 'content', 'conversion'], true) && !$changes && empty($s['manual_steps'])) continue; // nothing actionable
        $pdo->prepare("INSERT INTO seo_suggestions (run_id, type, priority, title, why, evidence, changes, manual_steps, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$runId, $type, max(1, min(100, (int)($s['priority'] ?? 50))), mb_substr($s['title'], 0, 250), (string)($s['why'] ?? ''), json_encode($s['evidence'] ?? new stdClass, JSON_UNESCAPED_UNICODE),
                json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode(array_values((array)($s['manual_steps'] ?? [])), JSON_UNESCAPED_UNICODE), gNow()]);
        $added++;
    }
    $pdo->prepare("UPDATE seo_runs SET status='done', finished_at=?, added=?, summary=? WHERE id=?")->execute([gNow(), $added, "$added suggestions", $runId]);
    seoLog($pdo, 'weekly analysis', "Analysed Search Console + visitors + site; proposed $added improvements ({$m['model']})", 'success', null, $t0);

    // auto-apply: only after the admin's chosen date, only safe title/description fixes
    $autoN = 0;
    if ($cfg['auto_from'] !== '' && date('Y-m-d') >= $cfg['auto_from']) {
        $rows = $pdo->prepare("SELECT id FROM seo_suggestions WHERE run_id=? AND status='pending' AND type='meta'"); $rows->execute([$runId]);
        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $id) { [$ok2] = seoApprove($pdo, (int)$id, 'auto', true); if ($ok2) $autoN++; }
    }
    return [true, "$added suggestions" . ($autoN ? ", $autoN applied automatically" : '')];
}

/* ───────── applying a suggestion (one commit per file) ───────── */
function seoApprove(PDO $pdo, $id, $by, $onlySafe = false) {
    $st = $pdo->prepare("SELECT * FROM seo_suggestions WHERE id=?"); $st->execute([$id]); $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s || $s['status'] !== 'pending') return [false, 'Not pending'];
    [$repo, , , $root] = seoRepo(); if (!$repo) return [false, 'Website GitHub settings are missing'];
    $changes = json_decode($s['changes'], true) ?: [];
    if (!$changes) { $pdo->prepare("UPDATE seo_suggestions SET status='done', decided_by=?, decided_at=? WHERE id=?")->execute([$by, gNow(), $id]); return [true, 'Marked done']; }
    if ($onlySafe) foreach ($changes as $c) if (($c['op'] ?? '') !== 'set' || !seoIsMetaField($c['field'] ?? '')) return [false, 'Not a safe change for auto-apply'];
    $byFile = []; foreach ($changes as $c) $byFile[$c['path']][] = $c;
    $commits = []; $err = null;
    foreach ($byFile as $path => $list) {
        if (!seoSafePath($path, $root)) { $err = 'Path not allowed'; break; }
        $cur = seoReadFile($path); $sha = $cur['sha'] ?? null;
        if ($list[0]['op'] === 'create_post') {
            if ($cur) { $err = "A post already exists at $path"; break; }
            $json = $list[0]['json']; $json['published'] = false;
            $content = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; $msg = 'SEO draft post: ' . ($json['title'] ?? $path);
        } else {
            if (!$cur) { $err = "File not found: $path"; break; }
            $data = json_decode($cur['content'], true); if (!is_array($data)) { $err = "Unreadable JSON: $path"; break; }
            foreach ($list as $c) {
                if ($onlySafe && !seoIsMetaField($c['field'])) { $err = 'Not a safe field for auto-apply'; break 2; }
                [$has, $now] = seoGetPath($data, $c['field']);
                if (!$has || $now !== $c['before']) { $err = "The text changed on the site since the suggestion ($path " . $c['field'] . ') — run the agent again.'; break 2; }
                if (!seoSetPath($data, $c['field'], $c['after'])) { $err = 'Could not apply ' . $c['field']; break 2; }
            }
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; $msg = 'SEO: ' . mb_substr($s['title'], 0, 120);
        }
        [$ok, $commit, $e] = seoWriteFile($path, $content, $sha, $msg);
        if (!$ok) { $err = $e ?: 'Write failed'; break; }
        $commits[] = $commit;
    }
    if ($err) {
        $pdo->prepare("UPDATE seo_suggestions SET " . ($commits ? "status='failed'," : '') . " error=? WHERE id=?")->execute([mb_substr($err, 0, 480), $id]);
        return [false, $err];
    }
    $pdo->prepare("UPDATE seo_suggestions SET status='applied', decided_by=?, decided_at=?, commit_sha=?, auto_applied=?, error=NULL, measure_after=DATE_ADD(CURDATE(), INTERVAL 21 DAY) WHERE id=?")
        ->execute([$by, gNow(), implode(',', $commits), $by === 'auto' ? 1 : 0, $id]);
    seoLog($pdo, ($by === 'auto' ? 'auto-applied' : 'applied') . ' change', $s['title'] . ' (' . implode(',', $commits) . ')');
    return [true, 'Applied — live in 1–2 minutes'];
}

function seoRevert(PDO $pdo, $id, $by) {
    $st = $pdo->prepare("SELECT * FROM seo_suggestions WHERE id=?"); $st->execute([$id]); $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s || $s['status'] !== 'applied') return [false, 'Not applied'];
    $changes = json_decode($s['changes'], true) ?: []; $byFile = []; foreach ($changes as $c) $byFile[$c['path']][] = $c;
    foreach ($byFile as $path => $list) {
        if ($list[0]['op'] === 'create_post') continue;           // drafts are unpublished; leave the file
        $cur = seoReadFile($path); if (!$cur) return [false, "File not found: $path"];
        $data = json_decode($cur['content'], true);
        foreach ($list as $c) { [$has, $now] = seoGetPath($data, $c['field']); if ($has && $now === $c['after']) seoSetPath($data, $c['field'], $c['before']); else return [false, 'The text was edited since — revert it by hand in Pages & SEO.']; }
        [$ok, , $e] = seoWriteFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $cur['sha'], 'Revert SEO change: ' . mb_substr($s['title'], 0, 100));
        if (!$ok) return [false, $e];
    }
    $pdo->prepare("UPDATE seo_suggestions SET status='reverted', decided_by=?, decided_at=?, measure_after=NULL WHERE id=?")->execute([$by, gNow(), $id]);
    seoLog($pdo, 'reverted change', $s['title']);
    return [true, 'Reverted'];
}

/** 3 weeks after a change: compare the query's performance with what it was when suggested. */
function seoMeasure(PDO $pdo) {
    $rows = $pdo->query("SELECT id, evidence FROM seo_suggestions WHERE status='applied' AND measure_after IS NOT NULL AND measure_after <= CURDATE() AND result IS NULL LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $end = (new DateTime('now'))->modify('-2 days'); $start = (clone $end)->modify('-13 days');
    foreach ($rows as $r) {
        $ev = json_decode($r['evidence'], true) ?: []; $q = trim($ev['query'] ?? ''); if ($q === '') { $pdo->prepare("UPDATE seo_suggestions SET result=? WHERE id=?")->execute([json_encode(['note' => 'No single search to track']), $r['id']]); continue; }
        $g = seoGsc(['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'), 'dimensions' => ['query'], 'dimensionFilterGroups' => [['filters' => [['dimension' => 'query', 'operator' => 'equals', 'expression' => $q]]]], 'rowLimit' => 1]);
        if ($g === null) continue;
        $now = $g ? ['clicks' => (int)$g[0]['clicks'], 'impressions' => (int)$g[0]['impressions'], 'position' => round($g[0]['position'], 1)] : ['clicks' => 0, 'impressions' => 0, 'position' => null];
        $pdo->prepare("UPDATE seo_suggestions SET result=? WHERE id=?")->execute([json_encode(['before' => ['clicks' => (int)($ev['clicks'] ?? 0), 'impressions' => (int)($ev['impressions'] ?? 0), 'position' => $ev['position'] ?? null], 'after' => $now, 'query' => $q]), $r['id']]);
    }
}

/* ───────── CLI ───────── */
if (PHP_SAPI === 'cli') {
    $pdo = gPdo(); $cmd = $argv[1] ?? '';
    if ($cmd === 'job' && !empty($argv[2])) {
        $f = seoJobFile($argv[2]); $job = json_decode((string)@file_get_contents($f), true) ?: [];
        file_put_contents($f, json_encode($job + ['status' => 'running']));
        [$ok, $msg] = seoRun($pdo, $job['by'] ?? 'admin', $f);
        $job = json_decode((string)@file_get_contents($f), true) ?: []; $job['status'] = $ok ? 'done' : 'error'; $job[$ok ? 'step' : 'error'] = $msg; file_put_contents($f, json_encode($job)); exit;
    }
    if ($cmd === 'cron') { if (seoCfg($pdo)['enabled']) { $r = seoRun($pdo, 'weekly'); echo $r[1], "\n"; } seoMeasure($pdo); exit; }
    if ($cmd === 'run') { echo seoRun($pdo, $argv[2] ?? 'cli')[1], "\n"; exit; }
    if ($cmd === 'measure') { seoMeasure($pdo); exit; }
    exit(1);
}

/* ───────── HTTP API (admin only) ───────── */
$me = gRequireTeam(['admin']);
$pdo = gPdo(); $act = $_GET['action'] ?? ''; $b = $_SERVER['REQUEST_METHOD'] === 'POST' ? gBody() : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $o = $_SERVER['HTTP_ORIGIN'] ?? ''; if ($o && parse_url($o, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) gOut(403, ['error' => 'Bad origin']); }
$row = function (array $r) { foreach (['evidence', 'changes', 'manual_steps', 'result'] as $k) $r[$k] = json_decode((string)$r[$k], true) ?: ($k === 'evidence' || $k === 'result' ? new stdClass : []); return $r; };

try {
switch ($act) {
case 'overview': {
    $counts = $pdo->query("SELECT status, COUNT(*) FROM seo_suggestions GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $last = $pdo->query("SELECT * FROM seo_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    [$repo] = seoRepo();
    gOut(200, ['counts' => $counts, 'last_run' => $last, 'settings' => seoCfg($pdo), 'model' => seoAgentModel($pdo)['model'],
        'gsc' => defined('GSC_KEY_FILE') && GSC_KEY_FILE && is_readable(GSC_KEY_FILE), 'github' => $repo !== '']);
}
case 'settings': {
    if (isset($b['enabled'])) seoSave($pdo, 'enabled', $b['enabled'] ? '1' : '0');
    if (array_key_exists('auto_from', $b)) seoSave($pdo, 'auto_from', preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['auto_from']) ? $b['auto_from'] : '');
    if (isset($b['markets'])) seoSave($pdo, 'markets', implode(',', array_intersect((array)$b['markets'], ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM'])));
    if (isset($b['per_run'])) seoSave($pdo, 'per_run', (string)max(3, min(15, (int)$b['per_run'])));
    if (isset($b['notes'])) seoSave($pdo, 'notes', mb_substr((string)$b['notes'], 0, 1500));
    gOut(200, ['ok' => true, 'settings' => seoCfg($pdo)]);
}
case 'run': {
    $id = bin2hex(random_bytes(8));
    file_put_contents(seoJobFile($id), json_encode(['status' => 'queued', 'step' => 'Starting…', 'by' => $me['email'], 'started' => time()]));
    $php = defined('PHP_CLI_BIN') ? PHP_CLI_BIN : (is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php');
    exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' job ' . escapeshellarg($id) . ' > /dev/null 2>&1 &');
    gOut(200, ['ok' => true, 'job' => $id]);
}
case 'job_status': {
    $j = json_decode((string)@file_get_contents(seoJobFile($_GET['job'] ?? '')), true);
    if (!$j) gOut(404, ['error' => 'Job not found']);
    if (!in_array($j['status'], ['done', 'error'], true) && time() - ($j['started'] ?? time()) > 1500) $j = ['status' => 'error', 'error' => 'The run timed out — try again.'];
    gOut(200, array_intersect_key($j, array_flip(['status', 'step', 'error'])));
}
case 'list': {
    $f = $_GET['status'] ?? 'pending'; $in = ['pending' => ['pending'], 'applied' => ['applied', 'failed'], 'closed' => ['rejected', 'reverted', 'done']][$f] ?? ['pending'];
    $st = $pdo->prepare("SELECT * FROM seo_suggestions WHERE status IN (" . implode(',', array_fill(0, count($in), '?')) . ") ORDER BY " . ($f === 'pending' ? 'priority DESC, id DESC' : 'decided_at DESC, id DESC') . " LIMIT 150");
    $st->execute($in); gOut(200, ['rows' => array_map($row, $st->fetchAll(PDO::FETCH_ASSOC))]);
}
case 'save_changes': {
    // the admin edited the proposed wording before approving
    $id = (int)($b['id'] ?? 0); $st = $pdo->prepare("SELECT changes FROM seo_suggestions WHERE id=? AND status='pending'"); $st->execute([$id]);
    $cur = json_decode((string)$st->fetchColumn(), true); if (!is_array($cur)) gOut(404, ['error' => 'Not found']);
    foreach ((array)($b['edits'] ?? []) as $i => $txt) {
        if (!isset($cur[$i])) continue; $clean = seoCleanText(is_array($txt) ? '' : $txt); if ($clean === null) gOut(400, ['error' => 'Scripts are not allowed']);
        if ($cur[$i]['op'] === 'set') $cur[$i]['after'] = $clean;
    }
    foreach ((array)($b['post_edits'] ?? []) as $i => $p) if (isset($cur[$i]) && $cur[$i]['op'] === 'create_post' && is_array($p)) foreach (['title', 'seoTitle', 'description'] as $k) if (isset($p[$k]) && seoCleanText($p[$k]) !== null) $cur[$i]['json'][$k] = $p[$k];
    $pdo->prepare("UPDATE seo_suggestions SET changes=? WHERE id=?")->execute([json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    gOut(200, ['ok' => true]);
}
case 'approve': { [$ok, $msg] = seoApprove($pdo, (int)($b['id'] ?? 0), $me['email']); gOut($ok ? 200 : 400, $ok ? ['ok' => true, 'message' => $msg] : ['error' => $msg]); }
case 'revert': { [$ok, $msg] = seoRevert($pdo, (int)($b['id'] ?? 0), $me['email']); gOut($ok ? 200 : 400, $ok ? ['ok' => true] : ['error' => $msg]); }
case 'reject': { $pdo->prepare("UPDATE seo_suggestions SET status='rejected', decided_by=?, decided_at=? WHERE id=? AND status='pending'")->execute([$me['email'], gNow(), (int)($b['id'] ?? 0)]); gOut(200, ['ok' => true]); }
case 'done': { $pdo->prepare("UPDATE seo_suggestions SET status='done', decided_by=?, decided_at=? WHERE id=? AND status='pending'")->execute([$me['email'], gNow(), (int)($b['id'] ?? 0)]); gOut(200, ['ok' => true]); }
default: gOut(400, ['error' => 'Unknown action']);
}
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "doesn't exist")) gOut(500, ['error' => 'Run migration-seo.sql first', 'setup' => true]);
    error_log('[seo-agent] ' . $e->getMessage()); gOut(500, ['error' => 'Database error']);
}
