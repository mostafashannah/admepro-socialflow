<?php
/**
 * file-preview.php — public, no-login viewer for a single storage file,
 * with a real Download button. Used as the link posted to Trello for a
 * forwarded attachment: the raw storage URL alone just renders bare in
 * the browser (an image with no page chrome at all, no way to save it
 * short of a manual right-click), which is exactly what "no download
 * button" was reporting.
 *
 * GET ?u=<url-encoded storage URL>&n=<optional display name>
 * Only ever points at THIS server's own storage — never proxies or embeds
 * an arbitrary external URL (open-redirect/XSS surface), so `u` is
 * validated to originate from this site's own /storage/ path before use.
 */

require_once __DIR__ . '/config.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// `p` carries only the storage-relative path (e.g. /storage/public/...),
// never a full nested URL — a query param that embeds another "https://"
// URL inside it trips Trello's link-safety interstitial ("Check this
// link") even when it points at the same domain. The legacy `u` param
// (a full URL) is still accepted for any already-posted Trello links.
$name = trim($_GET['n'] ?? '') ?: 'Attachment';
$selfHost = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$path = $_GET['p'] ?? '';
if ($path !== '') {
    $isOwnStorage = strpos($path, '/storage/') === 0;
    $url = $isOwnStorage ? "{$scheme}://{$selfHost}{$path}" : '';
} else {
    $url = $_GET['u'] ?? '';
    $parsed = parse_url($url);
    $isOwnStorage = $url && !empty($parsed['host']) && $parsed['host'] === $selfHost
        && isset($parsed['path']) && strpos($parsed['path'], '/storage/') === 0;
}

if (!$isOwnStorage) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;text-align:center;color:#666">Invalid or missing file link.</body>';
    exit;
}

$cleanPath = strtolower(explode('?', $url)[0]);
$isImage = (bool) preg_match('/\.(jpe?g|png|gif|webp)$/', $cleanPath);
$isVideo = (bool) preg_match('/\.(mp4|mov|webm|m4v)$/', $cleanPath);
$isPdf = (bool) preg_match('/\.pdf$/', $cleanPath);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($name) ?></title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0b0d;margin:0;padding:0;color:#fff;min-height:100vh;display:flex;flex-direction:column}
  .bar{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#17171a;border-bottom:1px solid #2a2a2e}
  .name{font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70vw}
  .dl{display:inline-flex;align-items:center;gap:6px;background:#ef4444;color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:9px 16px;border-radius:8px}
  .stage{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;overflow:auto}
  img,video{max-width:100%;max-height:80vh;border-radius:8px;display:block}
  iframe{width:90vw;height:80vh;border:none;border-radius:8px;background:#fff}
  .fallback{text-align:center;color:#aaa;font-size:14px}
</style>
</head>
<body>
  <div class="bar">
    <span class="name"><?= h($name) ?></span>
    <a class="dl" href="<?= h($url) ?>" download="<?= h($name) ?>">⬇ Download</a>
  </div>
  <div class="stage">
    <?php if ($isImage): ?>
      <img src="<?= h($url) ?>" alt="<?= h($name) ?>">
    <?php elseif ($isVideo): ?>
      <video src="<?= h($url) ?>" controls autoplay playsinline></video>
    <?php elseif ($isPdf): ?>
      <iframe src="<?= h($url) ?>"></iframe>
    <?php else: ?>
      <div class="fallback">No inline preview for this file type — use Download above.</div>
    <?php endif; ?>
  </div>
</body>
</html>
