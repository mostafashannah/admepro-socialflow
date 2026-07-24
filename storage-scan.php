<?php
// ================================================================
// SocialFlow — Storage analysis for Settings → Storage.
//
// Answers two questions the admin can't otherwise answer without SSH:
//   1. "Where did my disk usage go?" (per-folder breakdown under uploads/
//      socialflow-media, plus MySQL data size)
//   2. "What's actually safe to delete?" (files on disk that no row in
//      any table references anymore — "orphans")
//
// Orphan detection is deliberately generic instead of a hand-maintained
// per-table/column whitelist: every table gets dumped and scanned for any
// substring that looks like one of our own storage URLs. New features that
// store a file URL in some new column are covered automatically — no code
// change needed here every time a table grows a new *_url field. Base64-
// inline fields (e.g. client brand logos) never match this pattern, which
// is correct — they aren't files on disk at all.
//
// Actions (all via ?action=):
//   summary   (GET)  — folder sizes + file counts + DB size
//   orphans   (GET)  — candidate files not referenced anywhere, older than
//                      ?min_age_days= (default 2, to never flag something
//                      mid-upload or from a save that hasn't happened yet)
//   delete    (POST) — {paths:[...]} relative to socialflow-media/, each
//                      re-verified as still-orphaned right before removal
// ================================================================
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string) $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$mediaRoot = STORAGE_ROOT . '/socialflow-media';

function humanBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
}

// Every referenced storage path found anywhere in the database, as a set
// keyed by path relative to socialflow-media/ (e.g. "job-applications/
// portfolio/123_file.pdf"). Scans every table's every text-ish column —
// see the file header for why this beats a hand-maintained column list.
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
                foreach ($m[1] as $path) {
                    // Trailing punctuation/encoded characters occasionally
                    // ride along when the URL was embedded in prose (an AI
                    // summary, a pasted link) rather than a clean field.
                    $path = rtrim($path, '.,;:!?');
                    $referenced[urldecode($path)] = true;
                }
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
        if (is_dir($full)) {
            $out = array_merge($out, walkFiles($full, $baseLen));
        } else {
            $out[] = [
                'path' => substr($full, $baseLen),
                'size' => filesize($full),
                'mtime' => filemtime($full),
                'full' => $full,
            ];
        }
    }
    return $out;
}

$action = $_GET['action'] ?? 'summary';

if ($action === 'summary') {
    $baseLen = strlen($mediaRoot) + 1;
    $topLevel = @scandir($mediaRoot) ?: [];
    $folders = [];
    $totalBytes = 0;
    $totalFiles = 0;
    foreach ($topLevel as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $mediaRoot . '/' . $item;
        if (!is_dir($full)) continue;
        $files = walkFiles($full, $baseLen);
        $size = array_sum(array_column($files, 'size'));
        $folders[] = ['folder' => $item, 'sizeBytes' => $size, 'sizeHuman' => humanBytes($size), 'fileCount' => count($files)];
        $totalBytes += $size;
        $totalFiles += count($files);
    }
    usort($folders, fn($a, $b) => $b['sizeBytes'] <=> $a['sizeBytes']);

    $dbSize = 0;
    $dbStmt = $pdo->query("SELECT SUM(data_length + index_length) AS sz FROM information_schema.tables WHERE table_schema = DATABASE()");
    $dbRow = $dbStmt->fetch(PDO::FETCH_ASSOC);
    if ($dbRow && $dbRow['sz'] !== null) $dbSize = (int) $dbRow['sz'];

    echo json_encode([
        'folders' => $folders,
        'totalBytes' => $totalBytes,
        'totalHuman' => humanBytes($totalBytes),
        'totalFiles' => $totalFiles,
        'dbSizeBytes' => $dbSize,
        'dbSizeHuman' => humanBytes($dbSize),
    ]);
    exit;
}

if ($action === 'orphans') {
    $minAgeDays = isset($_GET['min_age_days']) ? max(0, (int) $_GET['min_age_days']) : 2;
    $cutoff = time() - ($minAgeDays * 86400);
    $baseLen = strlen($mediaRoot) + 1;
    $referenced = collectReferencedPaths($pdo);
    $allFiles = walkFiles($mediaRoot, $baseLen);
    $orphans = [];
    $orphanBytes = 0;
    foreach ($allFiles as $f) {
        if (isset($referenced[$f['path']])) continue;
        if ($f['mtime'] > $cutoff) continue; // too recent — could be mid-save
        $orphans[] = ['path' => $f['path'], 'sizeBytes' => $f['size'], 'sizeHuman' => humanBytes($f['size']), 'mtime' => date('c', $f['mtime'])];
        $orphanBytes += $f['size'];
    }
    usort($orphans, fn($a, $b) => $b['sizeBytes'] <=> $a['sizeBytes']);
    echo json_encode([
        'orphans' => $orphans,
        'orphanCount' => count($orphans),
        'orphanBytes' => $orphanBytes,
        'orphanHuman' => humanBytes($orphanBytes),
        'minAgeDays' => $minAgeDays,
    ]);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $paths = is_array($body['paths'] ?? null) ? $body['paths'] : [];
    $actorEmail = trim((string) ($body['actor_email'] ?? ''));
    if (!$paths) { echo json_encode(['deleted' => [], 'skipped' => []]); exit; }

    // Re-verify every path against a FRESH reference scan right before
    // deleting — the whole point of this endpoint is "never delete
    // something a live record still points to", and the scan the admin
    // saw on screen could be a few minutes stale by the time they click.
    $referenced = collectReferencedPaths($pdo);
    $deleted = [];
    $skipped = [];
    $freedBytes = 0;
    foreach ($paths as $rel) {
        $rel = ltrim((string) $rel, '/');
        // Prevent path traversal outside socialflow-media/ via a crafted path.
        $real = realpath($mediaRoot . '/' . $rel);
        if ($real === false || strpos($real, realpath($mediaRoot)) !== 0) { $skipped[] = ['path' => $rel, 'reason' => 'invalid path']; continue; }
        if (isset($referenced[$rel])) { $skipped[] = ['path' => $rel, 'reason' => 'now referenced — not deleted']; continue; }
        $size = @filesize($real) ?: 0;
        if (@unlink($real)) { $deleted[] = $rel; $freedBytes += $size; }
        else { $skipped[] = ['path' => $rel, 'reason' => 'delete failed']; }
    }
    if ($deleted) {
        $log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'storage', :details, 'success', :actor)");
        $log->execute([
            ':action' => 'Storage cleanup: deleted ' . count($deleted) . ' orphaned file(s)',
            ':details' => humanBytes($freedBytes) . ' freed. Files: ' . implode(', ', array_slice($deleted, 0, 20)) . (count($deleted) > 20 ? ' …' : ''),
            ':actor' => $actorEmail ?: 'system',
        ]);
    }
    echo json_encode(['deleted' => $deleted, 'skipped' => $skipped, 'freedBytes' => $freedBytes, 'freedHuman' => humanBytes($freedBytes)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
