<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// Removes Prospecting entries that cannot be reached (no valid email and no phone on any contact).
//   php prospects-cleanup.php           dry run: prints what WOULD change
//   php prospects-cleanup.php apply     writes the changes
// Only prospects with status "new" and no lead are deleted (nothing approved/contacted/won is touched).
// Prospects that still have at least one reachable contact keep only their reachable contacts.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sales-lib.php';
$apply = (($argv[1] ?? '') === 'apply');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $pdo->query("SELECT id, company, status, lead_id, contacts FROM sales_prospects ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$n = ['checked' => 0, 'deleted' => 0, 'trimmed' => 0, 'kept' => 0, 'protected' => 0];
$del = $pdo->prepare("DELETE FROM sales_prospects WHERE id = ?");
$upd = $pdo->prepare("UPDATE sales_prospects SET contacts = ? WHERE id = ?");
foreach ($rows as $r) {
    $n['checked']++;
    $all = json_decode((string)$r['contacts'], true); if (!is_array($all)) $all = [];
    $ok = saReachable($all);
    if ($ok && count($ok) === count($all)) { $n['kept']++; continue; }
    if (!$ok) {
        if ($r['status'] !== 'new' || !empty($r['lead_id'])) { $n['protected']++; echo "KEEP (already {$r['status']}) {$r['company']}\n"; continue; }
        echo "DELETE {$r['company']}  (" . count($all) . " contact(s), none with email/phone)\n";
        if ($apply) $del->execute([$r['id']]);
        $n['deleted']++;
    } else {
        echo "TRIM   {$r['company']}  " . count($all) . " -> " . count($ok) . " reachable contact(s)\n";
        if ($apply) $upd->execute([json_encode($ok, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
        $n['trimmed']++;
    }
}
echo "\n" . ($apply ? 'APPLIED' : 'DRY RUN (nothing changed)') . ': ' . json_encode($n) . "\n";
