<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * website-publish-lib.php — publish an article to a CLIENT's website through its GitHub repo (the site rebuilds itself on push, like admepro.com).
 *
 * The connection is an integration with app_key = 'website' and credentials:
 *   page_id      "owner/repo"            access_token  GitHub token with contents write access
 *   branch       "main"                  content_root  "src/content"      posts_dir  "posts"
 *   site_url     "https://client.com"    blog_path     "blog"             languages  "en" or "en,ar"
 *
 * Each site has its own article format, so the format is LEARNED from the site's own existing articles (same keys, same per-language
 * structure, same image path style) and Claude writes the new article in exactly that shape — translating into the site's other
 * language(s) when it has them. Nothing is guessed about the layout: if the repo has no articles to copy from, it says so.
 */

function wp_gh(string $method, string $repo, string $token, string $path, ?array $body = null): array {
    $ch = curl_init('https://api.github.com/repos/' . $repo . $path);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: SocialFlow-Website-Publisher', 'Content-Type: application/json']];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$res, true)];
}
function wp_path(string $p): string { return implode('/', array_map('rawurlencode', explode('/', $p))); }

/** Reads the repo: existing article files, image files by name, tags list. */
function wp_scan(string $repo, string $token, string $branch, string $root, string $postsDir): array {
    [$c, $r] = wp_gh('GET', $repo, $token, '/git/trees/' . rawurlencode($branch) . '?recursive=1');
    if ($c !== 200) return ['error' => $c === 404 ? 'GitHub cannot find this repo or branch (check "owner/repo", the branch and that the token has access).' : ($c === 401 ? 'GitHub rejected the token (expired or wrong).' : "GitHub HTTP $c")];
    $base = ($root !== '' ? $root . '/' : '') . $postsDir . '/';
    $files = []; $imgs = []; $tags = null;
    foreach ($r['tree'] ?? [] as $t) {
        if (($t['type'] ?? '') !== 'blob') continue;
        $p = $t['path'];
        if (strpos($p, $base) === 0 && preg_match('#^[^/_][^/]*\.json$#', substr($p, strlen($base)))) $files[] = $p;
        if (preg_match('#\.(jpe?g|png|webp|gif)$#i', $p)) $imgs[strtolower(basename($p))][] = $p;
        if ($p === ($root !== '' ? $root . '/' : '') . 'tags.json') $tags = $p;
    }
    return ['files' => $files, 'imgs' => $imgs, 'tags' => $tags, 'base' => $base, 'truncated' => !empty($r['truncated'])];
}

function wp_get_json(string $repo, string $token, string $branch, string $path): ?array {
    [$c, $r] = wp_gh('GET', $repo, $token, '/contents/' . wp_path($path) . '?ref=' . rawurlencode($branch));
    if ($c !== 200) return null;
    $j = json_decode((string)base64_decode((string)($r['content'] ?? '')), true);
    return is_array($j) ? $j : null;
}

function wp_slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim((string)$s, '-');
    // Arabic-only titles: keep a short ascii-safe slug from the date + id so the URL and file name are always valid.
    if ($s === '' || !preg_match('/[a-z0-9]/', $s)) $s = 'article-' . date('Ymd-His');
    return mb_substr($s, 0, 70);
}

/** First string in a structure that looks like an image path. */
function wp_find_image_value($v) {
    if (is_string($v)) return preg_match('#\.(jpe?g|png|webp|gif)(\?.*)?$#i', $v) ? $v : null;
    if (is_array($v)) foreach ($v as $x) { $f = wp_find_image_value($x); if ($f !== null) return $f; }
    return null;
}
function wp_replace_image_values($v, string $token, string $value) {
    if (is_string($v)) return $v === $token ? $value : $v;
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = wp_replace_image_values($x, $token, $value); }
    return $v;
}

function wp_claude(string $prompt, int $maxTokens = 8000): array {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'YOUR_ANTHROPIC_API_KEY_HERE') return [false, 'The AI key is not set on the server.'];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-5-5', 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err || $code < 200 || $code >= 300) return [false, 'The AI could not write the article (' . ($err ?: "HTTP $code") . ')'];
    $t = ''; foreach ((json_decode((string)$res, true)['content'] ?? []) as $b) $t .= $b['text'] ?? '';
    return [true, $t];
}

/**
 * Publishes one article. $post is the posts row. Returns [http_code, response] like the other publishers:
 * 200 + ['id' => slug, 'post_id' => slug, 'url' => ...] or [code, ['error' => ...]].
 */
function website_publish_article(PDO $pdo, array $creds, array $post): array {
    $repo = trim((string)($creds['page_id'] ?? '')); $token = trim((string)($creds['access_token'] ?? ''));
    if ($repo === '' || $token === '') return [400, ['error' => 'The website connection has no repo or token (Client → Settings → Integrations → Website).']];
    $branch = trim((string)($creds['branch'] ?? '')) ?: 'main';
    $root = trim((string)($creds['content_root'] ?? 'src/content'), '/');
    $postsDir = trim((string)($creds['posts_dir'] ?? 'posts'), '/') ?: 'posts';
    $site = rtrim(trim((string)($creds['site_url'] ?? '')), '/');
    $blogPath = trim((string)($creds['blog_path'] ?? 'blog'), '/');
    $langs = array_values(array_filter(array_map('trim', explode(',', strtolower((string)($creds['languages'] ?? 'en')))))) ?: ['en'];

    $title = trim((string)($post['title'] ?? '')); $body = trim((string)($post['caption'] ?? ''));
    if ($body === '') return [400, ['error' => 'This article has no text yet (the caption holds the article body).']];

    $scan = wp_scan($repo, $token, $branch, $root, $postsDir);
    if (isset($scan['error'])) return [502, ['error' => $scan['error']]];
    if (!$scan['files']) return [422, ['error' => "No articles were found in \"{$scan['base']}\" to copy the format from. Publish one article by hand once, or set the correct \"Articles folder\" in the website connection."]];

    // learn the format from up to 2 existing articles
    $samples = []; $samplePaths = [];
    foreach (array_slice(array_reverse($scan['files']), 0, 6) as $p) { $j = wp_get_json($repo, $token, $branch, $p); if ($j) { $samples[] = $j; $samplePaths[] = $p; } if (count($samples) >= 2) break; }
    if (!$samples) return [422, ['error' => 'The existing articles could not be read as JSON, so the format could not be learned.']];
    $tagList = '';
    if ($scan['tags']) { $tj = wp_get_json($repo, $token, $branch, $scan['tags']); if ($tj) $tagList = json_encode($tj, JSON_UNESCAPED_UNICODE); }

    // brand voice
    $brand = '';
    if (!empty($post['client_id'])) {
        try { $st = $pdo->prepare("SELECT tone, dos, donts, general_info FROM client_knowledge WHERE client_id = :c LIMIT 1"); $st->execute([':c' => $post['client_id']]);
            if ($k = $st->fetch(PDO::FETCH_ASSOC)) $brand = "Brand tone: " . ($k['tone'] ?? '') . "\nDo: " . ($k['dos'] ?? '') . "\nDon't: " . ($k['donts'] ?? '') . "\n"; } catch (Throwable $e) {}
    }

    // the article image: the last file added to the task (same rule as the social publishers)
    $imgUrl = '';
    $du = json_decode((string)($post['design_urls'] ?? '[]'), true) ?: []; $da = json_decode((string)($post['design_assets'] ?? '[]'), true) ?: [];
    foreach ($da as $x) { if (!empty($x['pick']) && ($x['kind'] ?? '') !== 'story') $imgUrl = (string)($x['url'] ?? $x['file_url'] ?? ''); }   // the file the account team picked
    if ($imgUrl !== '') { /* picked */ } elseif ($du) $imgUrl = (string)end($du); elseif ($da) { $last = end($da); $imgUrl = (string)($last['url'] ?? $last['file_url'] ?? ''); }
    if ($imgUrl !== '' && !preg_match('#\.(jpe?g|png|webp|gif)(\?|$)#i', $imgUrl) && !preg_match('#^https?://#', $imgUrl)) $imgUrl = '';

    $slugHint = wp_slug($title);
    $prompt = "You write articles for a client's website. The site stores each article as ONE JSON file. Below are real existing articles from this site: copy their format EXACTLY — the same keys, the same nesting, the same value styles (dates, tags, categories, per-language objects, markdown or html in the body, etc.). Do not add keys that the examples do not have, and do not drop keys they have.\n\n"
        . "EXAMPLE 1:\n" . json_encode($samples[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        . (isset($samples[1]) ? "\nEXAMPLE 2:\n" . json_encode($samples[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n" : "")
        . ($tagList !== '' ? "\nALLOWED TAGS / CATEGORIES (use only values from here, a wrong value breaks the site build):\n$tagList\n" : "")
        . "\nWRITE THE NEW ARTICLE from this source (written by the agency's content team):\nTITLE: $title\nTEXT:\n$body\n" . (!empty($post['hashtags']) ? "HASHTAGS (topic hints only, never copy the # signs): {$post['hashtags']}\n" : "")
        . ($brand !== '' ? "\n$brand" : "")
        . "\nRULES:\n- Languages of this site: " . implode(', ', $langs) . ". " . (count($langs) > 1
            ? "Where the examples keep a value per language (an object keyed by language code), fill EVERY language: keep the source language as written and TRANSLATE it faithfully into the other language(s), natural and professional, same meaning, names and numbers unchanged."
            : "Write English only; if the source is in another language translate it to English.") . "\n"
        . "- Keep the meaning and facts of the source; polish headings and structure like the examples do. Do not invent facts, numbers, quotes or dates.\n"
        . "- slug: lowercase latin letters, digits and hyphens, based on the English title (suggestion: $slugHint).\n"
        . "- date: today, " . date('Y-m-d') . ", in the same format the examples use.\n"
        . "- For the main image field(s) use exactly the text __IMAGE__ (the file is added separately).\n"
        . "- If the examples have a published/draft flag, set it to published.\n"
        . "Return ONLY the JSON object, nothing else.";
    [$ok, $txt] = wp_claude($prompt);
    if (!$ok) return [502, ['error' => $txt]];
    if (!preg_match('/\{[\s\S]*\}/', $txt, $m)) return [502, ['error' => 'The AI did not return an article in JSON form. Try again.']];
    $art = json_decode($m[0], true);
    if (!is_array($art)) return [502, ['error' => 'The AI returned broken JSON for the article. Try again.']];
    foreach (array_keys($samples[0]) as $k) if (!array_key_exists($k, $art)) return [502, ['error' => "The generated article is missing the field \"$k\" that the site's articles have. Try again."]];

    $slug = wp_slug((string)($art['slug'] ?? $slugHint)); $art['slug'] = isset($samples[0]['slug']) ? $slug : ($art['slug'] ?? $slug);
    $dir = dirname($samplePaths[0]);
    $jsonPath = $dir . '/' . $slug . '.json';

    // already published (a retry after a half-finished run): never create a second copy
    [$ec] = wp_gh('GET', $repo, $token, '/contents/' . wp_path($jsonPath) . '?ref=' . rawurlencode($branch));
    if ($ec === 200) return [200, ['id' => $slug, 'post_id' => $slug, 'url' => $site ? "$site/" . ($blogPath !== '' ? "$blogPath/" : '') . "$slug/" : null, 'note' => 'already on the site']];

    // image: put it next to the sample's images, same path style as the sample
    $sampleImg = wp_find_image_value($samples[0]);
    $imageValue = $sampleImg;   // safe fallback: reuse the sample's image so the build never points at a missing file
    $imgCommit = null;
    if ($imgUrl !== '' && $sampleImg) {
        $bn = strtolower(basename(parse_url($sampleImg, PHP_URL_PATH) ?: $sampleImg));
        $hit = $scan['imgs'][$bn][0] ?? null;
        $ch = curl_init($imgUrl); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_MAXFILESIZE => 12 * 1024 * 1024]);
        $bin = curl_exec($ch); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        $ext = preg_match('#image/(jpeg|png|webp|gif)#', $ct, $mm) ? str_replace('jpeg', 'jpg', $mm[1]) : strtolower(pathinfo(parse_url($imgUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if ($hit && $bin && strlen($bin) > 500 && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $imgPath = dirname($hit) . '/' . $slug . '.' . $ext;
            [$ic, $ir] = wp_gh('PUT', $repo, $token, '/contents/' . wp_path($imgPath), ['message' => 'Add article image: ' . $slug, 'content' => base64_encode($bin), 'branch' => $branch]);
            if ($ic === 200 || $ic === 201) {
                $sv = $sampleImg; $pre = substr($sv, 0, strlen($sv) - strlen(basename(parse_url($sv, PHP_URL_PATH) ?: $sv)));
                $imageValue = $pre . $slug . '.' . $ext; $imgCommit = $imgPath;
            }
        }
    }
    if ($imageValue !== null) $art = wp_replace_image_values($art, '__IMAGE__', $imageValue);

    $out = json_encode($art, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    $out = preg_replace_callback('/^( +)/m', fn($mt) => str_repeat(' ', intdiv(strlen($mt[1]), 2)), $out);   // 2-space indent like the site's files
    [$pc, $pr] = wp_gh('PUT', $repo, $token, '/contents/' . wp_path($jsonPath), ['message' => 'Add article: ' . mb_substr($title ?: $slug, 0, 80), 'content' => base64_encode($out), 'branch' => $branch]);
    if ($pc !== 200 && $pc !== 201) return [502, ['error' => 'GitHub did not accept the article (' . ($pr['message'] ?? "HTTP $pc") . ')']];
    return [200, ['id' => $slug, 'post_id' => $slug, 'url' => $site ? "$site/" . ($blogPath !== '' ? "$blogPath/" : '') . "$slug/" : null, 'path' => $jsonPath, 'image' => $imgCommit]];
}

/** Checks a connection (used by the "Test connection" button): repo + token access, and the article format it found. */
function website_test_connection(array $creds): array {
    $repo = trim((string)($creds['page_id'] ?? '')); $token = trim((string)($creds['access_token'] ?? ''));
    if ($repo === '' || $token === '') return ['ok' => false, 'error' => 'Enter the repo ("owner/name") and the GitHub token first.'];
    $branch = trim((string)($creds['branch'] ?? '')) ?: 'main'; $root = trim((string)($creds['content_root'] ?? 'src/content'), '/');
    $postsDir = trim((string)($creds['posts_dir'] ?? 'posts'), '/') ?: 'posts';
    [$c, $r] = wp_gh('GET', $repo, $token, '');
    if ($c !== 200) return ['ok' => false, 'error' => $c === 404 ? 'GitHub cannot find this repo (or the token cannot see it).' : ($c === 401 ? 'GitHub rejected the token.' : "GitHub HTTP $c")];
    if (empty($r['permissions']['push'])) return ['ok' => false, 'error' => 'The token can read the repo but cannot write to it. Give it "Contents: Read and write".'];
    $scan = wp_scan($repo, $token, $branch, $root, $postsDir);
    if (isset($scan['error'])) return ['ok' => false, 'error' => $scan['error']];
    $out = ['ok' => true, 'repo' => $r['full_name'] ?? $repo, 'articles' => count($scan['files']), 'folder' => $scan['base']];
    if (!$scan['files']) {
        // look for the likely folder so the person can fix the setting
        [$c2, $r2] = wp_gh('GET', $repo, $token, '/git/trees/' . rawurlencode($branch) . '?recursive=1'); $cand = [];
        foreach ($r2['tree'] ?? [] as $t) if (($t['type'] ?? '') === 'blob' && preg_match('#^(.*/(?:posts|blog|articles|news))/[^/_][^/]*\.json$#i', $t['path'], $m)) $cand[$m[1]] = true;
        $out['ok'] = false; $out['error'] = 'Connected, but no articles (JSON files) were found in "' . $scan['base'] . '".' . ($cand ? ' Found article folders: ' . implode(', ', array_keys($cand)) . ' — set Content folder + Articles folder to match.' : ' The format is learned from the site\'s existing articles, so publish one by hand first.');
        return $out;
    }
    $s = wp_get_json($repo, $token, $branch, $scan['files'][0]);
    $out['sample_keys'] = $s ? array_keys($s) : [];
    return $out;
}
