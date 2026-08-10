<?php
// One-off correction — client_documents.analyzed was only ever set to true
// in local browser state (never persisted, due to the id-mismatch bug just
// fixed in code), so every already-uploaded doc still shows analyzed=0 in
// the DB even though the AI analysis genuinely ran (client_knowledge got
// updated). If a client_knowledge row exists for that client with
// last_analyzed at/after the doc's upload time, the doc really was
// analyzed — mark it so.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$docs = $pdo->query("SELECT id, name, client_id, created_at, analyzed FROM client_documents WHERE analyzed = 0")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Unanalyzed-flagged documents ===\n";
foreach ($docs as $d) echo json_encode($d) . "\n";
if (!$docs) { echo "(none)\n"; exit; }

$ckStmt = $pdo->prepare("SELECT last_analyzed FROM client_knowledge WHERE client_id = ?");
$upd = $pdo->prepare("UPDATE client_documents SET analyzed = 1 WHERE id = ?");
$fixed = 0;
foreach ($docs as $d) {
    $ckStmt->execute([$d['client_id']]);
    $lastAnalyzed = $ckStmt->fetchColumn();
    if ($lastAnalyzed && strtotime($lastAnalyzed) >= strtotime($d['created_at']) - 60) {
        $upd->execute([$d['id']]);
        $fixed++;
    }
}
echo "\nMarked {$fixed} document(s) as analyzed.\n";
