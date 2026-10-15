<?php
// One-off CLI: fills assets.file_size for older uploads that never recorded
// it (they counted as 0 bytes toward the Client Assets storage bar).
// Usage: php backfill-asset-sizes.php
require_once __DIR__ . '/config.php';
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
$root = STORAGE_ROOT . '/socialflow-media/';
$rows = $pdo->query("SELECT id, file_url FROM assets WHERE (file_size IS NULL OR file_size=0) AND file_url LIKE '%/socialflow-media/%'")->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE assets SET file_size=? WHERE id=?");
$done = 0; $missing = 0;
foreach ($rows as $r) {
    if (!preg_match('#/socialflow-media/([^?\#]+)#', $r['file_url'], $m)) continue;
    $path = $root . urldecode($m[1]);
    if (is_file($path)) { $upd->execute([filesize($path), $r['id']]); $done++; } else { $missing++; }
}
echo "updated $done, file not found $missing, of " . count($rows) . "\n";
