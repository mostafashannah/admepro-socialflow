<?php
// ================================================================
// SocialFlow — public lead endpoint for the admepro.com website.
//
// The static website (admepro.com) posts its two forms here (quote form on
// /contact-us/, "Book a call" form on / and /gcc-ksa-ai-marketing-agency/).
// One POST = one row in `leads` (source='website', status='new'), assigned
// by the existing round-robin (assignLeadRotation) like any other new lead.
//
// Request: multipart/form-data
//   lead  = JSON {name, company, email, phone, notes, tags[], meta{...}}
//   brief = optional file (pdf/doc/docx/ppt/pptx/jpg/png/zip, max 10 MB)
// Response: 200 {"ok":true}  |  4xx {"error":"..."}
//
// Public by design (no key in the website code), so it is locked down with:
// an Origin allow-list, per-IP and global rate limits, strict length caps,
// a link-count spam check and a file whitelist. It never reads or returns any
// existing lead, and never updates anything.
// ================================================================
require_once __DIR__ . '/config.php';

$ALLOWED_ORIGINS = ['https://admepro.com', 'https://www.admepro.com', 'https://new.admepro.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Content-Type: application/json');
header('Vary: Origin');
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
if (!in_array($origin, $ALLOWED_ORIGINS, true)) { http_response_code(403); echo json_encode(['error' => 'Origin not allowed']); exit; }

function wl_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

// ---- rate limit: 5 per IP per 10 min, 60 total per hour (file based, no schema change)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$dir = sys_get_temp_dir() . '/website-lead-rl';
@mkdir($dir, 0700, true);
$now = time();
$hit = function (string $key, int $window, int $max) use ($dir, $now): bool {
    $f = $dir . '/' . md5($key) . '.json';
    $t = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $t = array_values(array_filter($t, fn($x) => $x > $now - $window));
    if (count($t) >= $max) return false;
    $t[] = $now;
    @file_put_contents($f, json_encode($t), LOCK_EX);
    return true;
};
if (!$hit('ip:' . $ip, 600, 5) || !$hit('all', 3600, 60)) wl_fail(429, 'Too many requests, please try again later');

// ---- parse + validate
$lead = json_decode($_POST['lead'] ?? '', true);
if (!is_array($lead)) wl_fail(400, 'Missing lead');
$clean = function ($v, int $max): string {
    $v = is_string($v) ? $v : '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v));
    return mb_substr($v, 0, $max);
};
$name    = $clean($lead['name'] ?? '', 120);
$company = $clean($lead['company'] ?? '', 160);
$email   = $clean($lead['email'] ?? '', 190);
$phone   = $clean($lead['phone'] ?? '', 40);
$notes   = $clean($lead['notes'] ?? '', 4000);
if ($name === '') wl_fail(422, 'Name is required');
if ($email === '' && $phone === '') wl_fail(422, 'Email or phone is required');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) wl_fail(422, 'Invalid email');
if (preg_match_all('#https?://#i', $notes . ' ' . $name . ' ' . $company) > 3) wl_fail(422, 'Too many links');
$tags = [];
foreach ((array)($lead['tags'] ?? []) as $t) { $t = $clean($t, 40); if ($t !== '' && count($tags) < 10) $tags[] = $t; }
$meta = is_array($lead['meta'] ?? null) ? $lead['meta'] : [];
$form = $clean($meta['form'] ?? '', 20);
$page = $clean($meta['page'] ?? '', 300);
$ref  = $clean($meta['referrer'] ?? '', 300);
$utm  = [];
foreach ((array)($meta['utm'] ?? []) as $k => $v) { if (preg_match('/^utm_[a-z_]{1,20}$/', (string)$k)) $utm[$k] = $clean($v, 100); }

// ---- optional brief file
$briefUrl = '';
if (!empty($_FILES['brief']['tmp_name']) && is_uploaded_file($_FILES['brief']['tmp_name'])) {
    $f = $_FILES['brief'];
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip'], true) || ($f['size'] ?? 0) > 10 * 1024 * 1024) wl_fail(422, 'File type or size not allowed');
    $up = __DIR__ . '/website-briefs';
    if (!is_dir($up)) { @mkdir($up, 0755, true); @file_put_contents($up . '/index.html', ''); @file_put_contents($up . '/.htaccess', "Options -Indexes\nphp_flag engine off\n"); }
    $fname = bin2hex(random_bytes(12)) . '.' . $ext;
    if (@move_uploaded_file($f['tmp_name'], $up . '/' . $fname)) $briefUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'socialflow.admepro.com') . '/website-briefs/' . $fname;
}

// ---- store
$srcTag = 'src_id:website:' . bin2hex(random_bytes(6));
$full = $notes . "\n\n— Website form: " . ($form ?: 'n/a') . ($page ? "\nPage: $page" : '') . ($ref ? "\nReferrer: $ref" : '')
      . ($utm ? "\nUTM: " . json_encode($utm, JSON_UNESCAPED_SLASHES) : '') . ($briefUrl ? "\nBrief file: $briefUrl" : '') . "\n\n$srcTag";
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    require_once __DIR__ . '/reply-bot-lib.php'; // assignLeadRotation()
    $am = null;
    try { $am = function_exists('assignLeadRotation') ? assignLeadRotation($pdo) : null; } catch (Throwable $e) { error_log('[website-lead] rotation: ' . $e->getMessage()); }
    $assignedTo = $am['email'] ?? null;
    $assignedAt = $am ? date('Y-m-d H:i:s') : null;
    try {
        $pdo->prepare("INSERT INTO leads (name, company, phone, email, source, status, platforms, notes, tags, category, assigned_to, assigned_at)
                       VALUES (:name, :company, :phone, :email, 'website', 'new', :platforms, :notes, :tags, 'lead', :to, :at)")
            ->execute([':name' => $name, ':company' => $company ?: null, ':phone' => $phone ?: null, ':email' => $email ?: null,
                       ':platforms' => json_encode(['website']), ':notes' => $full, ':tags' => json_encode($tags), ':to' => $assignedTo, ':at' => $assignedAt]);
    } catch (Throwable $e) {
        // older table without company/email/tags columns: keep those details inside the notes instead of losing the lead
        error_log('[website-lead] full insert failed, retrying minimal: ' . $e->getMessage());
        $extra = trim(($company ? "Company: $company\n" : '') . ($email ? "Email: $email\n" : '') . ($tags ? 'Tags: ' . implode(', ', $tags) . "\n" : ''));
        $pdo->prepare("INSERT INTO leads (name, phone, source, status, platforms, notes, category, assigned_to, assigned_at)
                       VALUES (:name, :phone, 'website', 'new', :platforms, :notes, 'lead', :to, :at)")
            ->execute([':name' => $name, ':phone' => $phone ?: null, ':platforms' => json_encode(['website']),
                       ':notes' => ($extra ? $extra . "\n" : '') . $full, ':to' => $assignedTo, ':at' => $assignedAt]);
    }
    // tell the assigned account manager on WhatsApp (best effort, same wording as other new leads)
    if ($am && !empty($am['id']) && function_exists('sendWhatsAppReply')) {
        try {
            $q = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id LIMIT 1"); $q->execute([':id' => $am['id']]);
            if ($wa = $q->fetchColumn()) sendWhatsAppReply($wa, "New website lead assigned to you: \"{$name}\"" . ($phone ? " — {$phone}" : '') . ($email ? " — {$email}" : '') . ". It's your turn in rotation.");
        } catch (Throwable $e) { error_log('[website-lead] notify: ' . $e->getMessage()); }
    }
    try {
        $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :a, 'leads', :d, 'success', 'website')")
            ->execute([':a' => 'New website lead (' . ($form ?: 'form') . ')', ':d' => "{$name} <{$email}> {$phone} page={$page}"]);
    } catch (Throwable $e) { /* best effort */ }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[website-lead] EXCEPTION: ' . $e->getMessage());
    wl_fail(500, 'Could not save, please email us instead');
}
