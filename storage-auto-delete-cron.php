<?php
// ================================================================
// SocialFlow — scheduled orphaned-file cleanup.
//
// Only acts if Settings → Storage has "Auto-delete orphaned files" turned
// on (stored in app_settings.feature_flags as storage_auto_delete_enabled
// + storage_auto_delete_days). Off by default — this never runs
// destructively unless an admin explicitly opts in. Reuses the exact same
// orphan-detection logic as storage-scan.php (generic DB-wide URL scan,
// not a hand-maintained column list) via a shared include.
//
// Suggested cron: */30 * * * * php /var/www/socialflow/storage-auto-delete-cron.php
// (cheap to run often — it's a no-op whenever the toggle is off)
// ================================================================
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$settingsRow = $pdo->query("SELECT feature_flags FROM app_settings ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$flags = $settingsRow ? (json_decode($settingsRow['feature_flags'] ?? '{}', true) ?: []) : [];

if (empty($flags['storage_auto_delete_enabled'])) {
    echo json_encode(['skipped' => true, 'reason' => 'storage_auto_delete_enabled is off']);
    exit;
}
$retentionDays = max(7, (int) ($flags['storage_auto_delete_days'] ?? 30));

function humanBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
}

function collectReferencedPaths(PDO $pdo) {
    $referenced = [];
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $urlPrefixPattern = '#/storage/public/socialflow-media/([^"\'\\\\\s\)\]]+)#i';
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT * FROM `" . str_replace('`', '', $table) . "`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $blob = implode(' ', array_filter($row, 'is_string'));
            if (strpos($blob, 'socialflow-media') === false) continue;
            if (preg_match_all($urlPrefixPattern, $blob, $m)) {
                foreach ($m[1] as $path) { $referenced[urldecode(rtrim($path, '.,;:!?'))] = true; }
            }
        }
    }
    return $referenced;
}

function walkFiles($dir, $baseLen) {
    $out = [];
    $items = @scandir($dir);
    if ($items === false) return $out;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dir . '/' . $item;
        if (is_dir($full)) { $out = array_merge($out, walkFiles($full, $baseLen)); }
        else { $out[] = ['path' => substr($full, $baseLen), 'size' => filesize($full), 'mtime' => filemtime($full), 'full' => $full]; }
    }
    return $out;
}

$mediaRoot = STORAGE_ROOT . '/socialflow-media';
$baseLen = strlen($mediaRoot) + 1;
$cutoff = time() - ($retentionDays * 86400);

$referenced = collectReferencedPaths($pdo);
$allFiles = walkFiles($mediaRoot, $baseLen);
$deleted = [];
$freedBytes = 0;
foreach ($allFiles as $f) {
    if (isset($referenced[$f['path']])) continue;
    if ($f['mtime'] > $cutoff) continue;
    $size = $f['size'];
    if (@unlink($f['full'])) { $deleted[] = $f['path']; $freedBytes += $size; }
}

if ($deleted) {
    $log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'storage', :details, 'success', 'auto-delete cron')");
    $log->execute([
        ':action' => 'Storage auto-delete: removed ' . count($deleted) . ' orphaned file(s) older than ' . $retentionDays . ' days',
        ':details' => humanBytes($freedBytes) . ' freed. Files: ' . implode(', ', array_slice($deleted, 0, 20)) . (count($deleted) > 20 ? ' …' : ''),
    ]);
}

echo json_encode(['deletedCount' => count($deleted), 'freedBytes' => $freedBytes, 'freedHuman' => humanBytes($freedBytes), 'retentionDays' => $retentionDays]);
