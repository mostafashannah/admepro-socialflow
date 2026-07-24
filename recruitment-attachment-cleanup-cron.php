<?php
// ================================================================
// SocialFlow — Recruitment → Cleanup Rules.
//
// Deletes CV/portfolio attachment FILES (not the application record
// itself) for candidates who have sat in a given pipeline stage for N
// days — e.g. "delete attachments for anything Rejected for 60+ days".
// Keeps the application's history/notes/AI score intact; only clears the
// file fields (cv_url, portfolio_attachment_url) and the physical files
// they pointed to, freeing storage from old candidates without ever
// touching active/recent pipeline stages.
//
// Rules live in app_settings.feature_flags.recruitment_cleanup_rules, a
// JSON array like [{"status":"rejected","days":60}], set from Recruitment
// → Cleanup Rules in the app. Off (empty array) by default.
//
// Two ways to invoke:
//   Cron (no auth, reads settings and runs quietly):
//     php recruitment-attachment-cleanup-cron.php
//   Manual "Run Now" from the app (?run=1, requires apikey header,
//   ignores the enabled rules array and runs whatever rules are POSTed —
//   lets an admin preview/run a rule before saving it permanently):
//     POST ?action=preview or ?action=run  {rules:[{status,days}]}
//
// Suggested cron: 0 4 * * * php /var/www/socialflow/recruitment-attachment-cleanup-cron.php >> /var/www/socialflow/recruitment-cleanup.log 2>&1
// ================================================================
require_once __DIR__ . '/config.php';

$isHttp = php_sapi_name() !== 'cli';
if ($isHttp) {
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
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function humanBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
}

// Only ever deletes a file that's actually ours (under our own storage
// URL) — a candidate's portfolio_url is very often an external Behance/
// Drive link, which this must never touch.
function deleteIfOwnStorageFile($url) {
    if (!$url || strpos($url, STORAGE_PUBLIC_URL . '/socialflow-media/') !== 0) return 0;
    $rel = substr($url, strlen(STORAGE_PUBLIC_URL . '/socialflow-media/'));
    $full = STORAGE_ROOT . '/socialflow-media/' . $rel;
    $real = realpath($full);
    $mediaRoot = realpath(STORAGE_ROOT . '/socialflow-media');
    if ($real === false || strpos($real, $mediaRoot) !== 0) return 0;
    $size = @filesize($real) ?: 0;
    return @unlink($real) ? $size : 0;
}

function runRules(PDO $pdo, array $rules, bool $dryRun) {
    $results = [];
    $totalFreed = 0;
    $stmtSelect = $pdo->prepare(
        "SELECT id, candidate_name, cv_url, portfolio_attachment_url FROM job_applications
         WHERE status = :status AND attachments_cleaned_at IS NULL
           AND status_updated_at IS NOT NULL AND status_updated_at < :cutoff
           AND (cv_url IS NOT NULL OR portfolio_attachment_url IS NOT NULL)"
    );
    $stmtClear = $pdo->prepare("UPDATE job_applications SET cv_url = NULL, portfolio_attachment_url = NULL, attachments_cleaned_at = NOW() WHERE id = :id");
    $stmtLog = $pdo->prepare("INSERT INTO job_application_activity (id, application_id, action, actor_name) VALUES (UUID(), :aid, :action, 'Storage cleanup')");

    foreach ($rules as $rule) {
        $status = (string) ($rule['status'] ?? '');
        $days = max(1, (int) ($rule['days'] ?? 0));
        if (!$status || !$days) continue;
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmtSelect->execute([':status' => $status, ':cutoff' => $cutoff]);
        $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
        $ruleFreed = 0;
        $candidates = [];
        foreach ($rows as $row) {
            $freed = 0;
            if (!$dryRun) {
                $freed += deleteIfOwnStorageFile($row['cv_url']);
                $freed += deleteIfOwnStorageFile($row['portfolio_attachment_url']);
                $stmtClear->execute([':id' => $row['id']]);
                $stmtLog->execute([':aid' => $row['id'], ':action' => 'CV/portfolio attachment removed by storage cleanup rule (' . $status . ', ' . $days . '+ days)']);
            }
            $ruleFreed += $freed;
            $candidates[] = $row['candidate_name'];
        }
        $totalFreed += $ruleFreed;
        $results[] = ['status' => $status, 'days' => $days, 'count' => count($rows), 'freedBytes' => $ruleFreed, 'freedHuman' => humanBytes($ruleFreed), 'candidates' => array_slice($candidates, 0, 30)];
    }
    return ['rules' => $results, 'totalFreedBytes' => $totalFreed, 'totalFreedHuman' => humanBytes($totalFreed)];
}

if ($isHttp) {
    $action = $_GET['action'] ?? 'preview';
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $rules = is_array($body['rules'] ?? null) ? $body['rules'] : [];
    if (!in_array($action, ['preview', 'run'], true)) { http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit; }
    $result = runRules($pdo, $rules, $action === 'preview');
    if ($action === 'run' && $result['rules']) {
        $totalCount = array_sum(array_column($result['rules'], 'count'));
        if ($totalCount > 0) {
            $log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'storage', :details, 'success', :actor)");
            $log->execute([
                ':action' => 'Recruitment cleanup: removed attachments for ' . $totalCount . ' application(s)',
                ':details' => $result['totalFreedHuman'] . ' freed. ' . json_encode($result['rules']),
                ':actor' => $body['actor_email'] ?? 'admin (manual run)',
            ]);
        }
    }
    echo json_encode($result);
    exit;
}

// CLI/cron path — reads the saved rules from settings, runs for real.
$settingsRow = $pdo->query("SELECT feature_flags FROM app_settings ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$flags = $settingsRow ? (json_decode($settingsRow['feature_flags'] ?? '{}', true) ?: []) : [];
$rules = is_array($flags['recruitment_cleanup_rules'] ?? null) ? $flags['recruitment_cleanup_rules'] : [];
if (!$rules) { echo json_encode(['skipped' => true, 'reason' => 'no cleanup rules configured']); exit; }

$result = runRules($pdo, $rules, false);
$totalCount = array_sum(array_column($result['rules'], 'count'));
if ($totalCount > 0) {
    $log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), :action, 'storage', :details, 'success', 'recruitment cleanup cron')");
    $log->execute([
        ':action' => 'Recruitment cleanup: removed attachments for ' . $totalCount . ' application(s)',
        ':details' => $result['totalFreedHuman'] . ' freed. ' . json_encode($result['rules']),
    ]);
}
echo json_encode($result);
