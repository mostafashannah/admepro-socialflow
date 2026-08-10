<?php
// READ-ONLY diagnostic — confirming TSC's client_knowledge.context_file
// actually contains the uploaded ChatGPT chat's extracted summary, and
// that the client_documents row is correctly linked, before telling the
// user Sara/Pro will actually see it.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%TSC%'")->fetch(PDO::FETCH_ASSOC);
echo "=== Client ===\n" . json_encode($client) . "\n";
if (!$client) exit;

$doc = $pdo->prepare("SELECT id, name, doc_type, char_count, analyzed, created_at FROM client_documents WHERE client_id = ? ORDER BY created_at DESC");
$doc->execute([$client['id']]);
echo "\n=== Documents ===\n";
foreach ($doc->fetchAll(PDO::FETCH_ASSOC) as $d) echo json_encode($d) . "\n";

$ck = $pdo->prepare("SELECT summary, tone, keywords, priorities, dos, donts, target_audience, LENGTH(context_file) as ctx_len, last_analyzed FROM client_knowledge WHERE client_id = ?");
$ck->execute([$client['id']]);
$ckRow = $ck->fetch(PDO::FETCH_ASSOC);
echo "\n=== client_knowledge (Profile) ===\n" . json_encode($ckRow) . "\n";

$ckFull = $pdo->prepare("SELECT context_file FROM client_knowledge WHERE client_id = ?");
$ckFull->execute([$client['id']]);
$ctx = $ckFull->fetchColumn();
echo "\n=== context_file — LAST 800 chars (what clientBrainBlock now reads) ===\n" . mb_substr((string)$ctx, -800) . "\n";

$mem = $pdo->prepare("SELECT `key`, value, type FROM client_memory WHERE client_id = ? AND type = 'document_extract'");
$mem->execute([$client['id']]);
echo "\n=== client_memory rows of type document_extract ===\n";
foreach ($mem->fetchAll(PDO::FETCH_ASSOC) as $m) echo json_encode($m) . "\n";
