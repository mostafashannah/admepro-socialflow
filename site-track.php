<?php
/**
 * site-track.php — privacy-friendly visitor counter for admepro.com (no cookies, no personal data).
 * The site sends a tiny beacon on each page view (and the time spent when the visitor leaves).
 * Visitors are counted with a daily-rotating anonymous hash; IPs are never stored.
 */
require_once __DIR__ . '/config.php';
$ALLOWED = ['https://admepro.com', 'https://www.admepro.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED, true)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if ($origin && !in_array($origin, $ALLOWED, true)) { http_response_code(403); exit; }

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|facebookexternalhit|whatsapp/i', $ua)) { http_response_code(204); exit; }
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); exit; }

$cut = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
$event = in_array($in['e'] ?? 'pageview', ['pageview', 'leave', 'lead', 'click'], true) ? $in['e'] : 'pageview';
$path = $cut(parse_url((string)($in['p'] ?? '/'), PHP_URL_PATH) ?: '/', 255);
$ref = $cut(parse_url((string)($in['r'] ?? ''), PHP_URL_HOST) ?: '', 255);
$ref = preg_replace('/^www\./', '', strtolower($ref));
if ($ref === 'admepro.com') $ref = '';
$utm = is_array($in['u'] ?? null) ? $in['u'] : [];
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$ip = trim(explode(',', $ip)[0]);
$tz = new DateTimeZone('Africa/Cairo'); $now = new DateTime('now', $tz);
// Anonymous visitor id: hash of (IP + browser + today + server secret). Changes every day, can't be reversed.
$secret = defined('API_KEY') ? API_KEY : (defined('DB_PASS') ? DB_PASS : 'sf');
$visitor = substr(hash('sha256', $ip . '|' . $ua . '|' . $now->format('Y-m-d') . '|' . $secret), 0, 16);
$device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
$browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Other')))));
$country = strtoupper(substr((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 2)) ?: null;
// No Cloudflare in front: use the browser's time zone (e.g. Africa/Cairo → EG). Language (en-US) is only a last resort —
// most Egyptians browse in English with a US locale, so it said "US" for them.
if (!$country) {
    $TZ = ['Africa/Cairo'=>'EG','Asia/Riyadh'=>'SA','Asia/Dubai'=>'AE','Asia/Kuwait'=>'KW','Asia/Qatar'=>'QA','Asia/Bahrain'=>'BH','Asia/Muscat'=>'OM','Asia/Amman'=>'JO',
        'Asia/Beirut'=>'LB','Asia/Baghdad'=>'IQ','Africa/Tripoli'=>'LY','Africa/Tunis'=>'TN','Africa/Algiers'=>'DZ','Africa/Casablanca'=>'MA','Africa/Khartoum'=>'SD',
        'Europe/London'=>'GB','Europe/Paris'=>'FR','Europe/Berlin'=>'DE','Europe/Madrid'=>'ES','Europe/Rome'=>'IT','Europe/Amsterdam'=>'NL','Europe/Istanbul'=>'TR',
        'Asia/Kolkata'=>'IN','Asia/Calcutta'=>'IN','Asia/Karachi'=>'PK','Asia/Singapore'=>'SG','Asia/Tokyo'=>'JP','Asia/Shanghai'=>'CN','Australia/Sydney'=>'AU',
        'America/Toronto'=>'CA','America/Vancouver'=>'CA','America/Sao_Paulo'=>'BR','America/Mexico_City'=>'MX'];
    $z = (string)($in['z'] ?? '');
    if (isset($TZ[$z])) $country = $TZ[$z];
    elseif (preg_match('#^America/(New_York|Chicago|Denver|Los_Angeles|Phoenix|Detroit|Anchorage|Indiana/|Kentucky/)#', $z) || $z === 'Pacific/Honolulu') $country = 'US';
    elseif ($z === '' && preg_match('/^[a-z]{2}-([A-Z]{2})/', (string)($in['l'] ?? ''), $m)) $country = $m[1];
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Light flood guard: at most 120 events per visitor per day.
    $c = $pdo->prepare("SELECT COUNT(*) FROM site_events WHERE visitor = ? AND day = ?"); $c->execute([$visitor, $now->format('Y-m-d')]);
    if ((int)$c->fetchColumn() > 120) { http_response_code(204); exit; }
    $pdo->prepare("INSERT INTO site_events (ts, day, event, path, title, ref_host, utm_source, utm_medium, utm_campaign, country, lang, device, browser, visitor, secs) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d'), $event, $path, $cut($in['t'] ?? '', 255) ?: null, $ref ?: null,
            $cut($utm['source'] ?? '', 100) ?: null, $cut($utm['medium'] ?? '', 100) ?: null, $cut($utm['campaign'] ?? '', 100) ?: null,
            $country, $cut($in['l'] ?? '', 8) ?: null, $device, $browser, $visitor, isset($in['s']) ? max(0, min(7200, (int)$in['s'])) : null]);
} catch (Throwable $e) { error_log('[site-track] ' . $e->getMessage()); }
http_response_code(204);
