<?php
// ================================================================
// SocialFlow — public, read-only feed of the open job positions.
//
// admepro.com/careers (the static website) calls this on page load to list the
// openings, so roles are only ever managed in SocialFlow. Applying still happens
// on SocialFlow itself (apply_url → /careers?opening=<id>), so the recruitment
// pipeline (CV upload, confirmation emails, interview/task/offer steps) is untouched.
//
// GET only. Returns only what careers.php already shows publicly for status='open'
// roles. No applicant data, no writes. CORS limited to the website's origins.
// ================================================================
require_once __DIR__ . '/config.php';

$ALLOWED_ORIGINS = ['https://admepro.com', 'https://www.admepro.com', 'https://new.admepro.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
header('Cache-Control: public, max-age=300');

$labels = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'freelance' => 'Freelance', 'contract' => 'Contract', 'internship' => 'Internship'];
$plain = fn($h, $n) => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$h))), 0, $n);
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = $pdo->query("SELECT id, title, department, location, employment_type, description, requirements, closing_date, created_at FROM job_openings WHERE status = 'open' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[careers-feed] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'Unavailable']); exit;
}
$base = 'https://socialflow.admepro.com/careers?opening=';
echo json_encode([
    'updated' => gmdate('c'),
    'applyAll' => 'https://socialflow.admepro.com/careers',
    'openings' => array_map(fn($o) => [
        'id' => $o['id'],
        'title' => $plain($o['title'], 160),
        'department' => $plain($o['department'], 80),
        'location' => $plain($o['location'], 120),
        'employmentType' => $o['employment_type'],
        'employmentLabel' => $labels[$o['employment_type']] ?? 'Full-time',
        'summary' => $plain($o['description'], 420),
        'requirements' => $plain($o['requirements'], 600),
        'closingDate' => $o['closing_date'] ?: null,
        'postedAt' => $o['created_at'] ? gmdate('c', strtotime($o['created_at'])) : null,
        'applyUrl' => $base . rawurlencode($o['id']),
    ], $rows),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
