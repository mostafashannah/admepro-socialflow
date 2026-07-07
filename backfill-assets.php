<?php
// ================================================================
// SocialFlow — one-time backfill: create Assets rows for media that
// was already attached to posts (design_urls / design_assets /
// carousel_cover) before the AssetPickerModal upload bug was fixed
// (that bug meant freshly-uploaded post media never got saved as an
// Asset). Idempotent — safe to run more than once, skips any file_url
// that already has an assets row.
//
// Run: curl -X POST https://socialflow.admepro.com/backfill-assets.php -H "apikey: <API_KEY>"
// ================================================================
require_once 'config.php';
header("Content-Type: application/json");

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function guessFileType($url) {
    if (preg_match('/\.(mp4|mov|webm|m4v)(\?|$)/i', $url)) return 'video';
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $url)) return 'image';
    return 'image';
}

$existing = $pdo->query("SELECT file_url FROM assets")->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_fill_keys($existing, true);

$posts = $pdo->query("SELECT id, title, project_id, design_urls, design_assets, carousel_cover FROM posts")->fetchAll(PDO::FETCH_ASSOC);

$ins = $pdo->prepare("INSERT INTO assets (id, name, file_url, file_type, category, tags, project_id) VALUES (UUID(), :name, :url, :type, :category, :tags, :project_id)");

$imported = 0;
$skipped = 0;

foreach ($posts as $post) {
    $candidates = []; // [url, name, kind]

    $designUrls = json_decode($post['design_urls'] ?? '[]', true) ?: [];
    foreach ($designUrls as $i => $url) {
        if (is_string($url) && $url) $candidates[] = [$url, ($post['title'] ?: 'Post') . ($i > 0 ? " ({$i})" : ''), null];
    }

    $designAssets = json_decode($post['design_assets'] ?? '[]', true) ?: [];
    foreach ($designAssets as $i => $a) {
        $url = $a['url'] ?? $a['file_url'] ?? null;
        if (!$url) continue;
        $name = $a['name'] ?? (($post['title'] ?: 'Post') . ($i > 0 ? " ({$i})" : ''));
        $candidates[] = [$url, $name, $a['kind'] ?? null];
    }

    if (!empty($post['carousel_cover'])) {
        $candidates[] = [$post['carousel_cover'], ($post['title'] ?: 'Post') . ' (cover)', 'cover'];
    }

    foreach ($candidates as [$url, $name, $kind]) {
        if (isset($existingSet[$url])) { $skipped++; continue; }
        $ins->execute([
            ':name' => $name,
            ':url' => $url,
            ':type' => guessFileType($url),
            ':category' => 'Backfilled',
            ':tags' => json_encode(array_filter(['backfilled', $kind])),
            ':project_id' => $post['project_id'],
        ]);
        $existingSet[$url] = true; // avoid re-inserting the same URL twice within this same run
        $imported++;
    }
}

echo json_encode(['ok' => true, 'imported' => $imported, 'skipped_already_existed' => $skipped]);
