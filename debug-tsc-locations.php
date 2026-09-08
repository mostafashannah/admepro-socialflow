<?php
// READ-ONLY diagnostic — checking whether "branch"/"location"/city names
// actually appear anywhere in TSC's stored document content, to determine
// whether Pro's search_client_document tool has real data to find (a
// content problem) or the tool itself isn't being invoked/working (a code
// problem).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%TSC%'")->fetch(PDO::FETCH_ASSOC);
echo "Client: " . json_encode($client) . "\n\n";

$docs = $pdo->prepare("SELECT id, name, LENGTH(content) as len, content FROM client_documents WHERE client_id = ?");
$docs->execute([$client['id']]);
$rows = $docs->fetchAll(PDO::FETCH_ASSOC);

$terms = ['branch', 'location', 'Riyadh', 'Jeddah', 'Dammam', 'Khobar', 'headquarters', 'HQ', 'office'];
foreach ($rows as $doc) {
    echo "=== {$doc['name']} (id={$doc['id']}, stored length={$doc['len']}) ===\n";
    foreach ($terms as $term) {
        $count = 0;
        $pos = 0;
        while (($pos = mb_stripos($doc['content'], $term, $pos)) !== false) { $count++; $pos += strlen($term); }
        if ($count > 0) {
            $firstPos = mb_stripos($doc['content'], $term);
            $excerpt = mb_substr($doc['content'], max(0, $firstPos - 150), 400);
            echo "  '{$term}': {$count} occurrence(s). First excerpt:\n  ..." . str_replace("\n", " ", trim($excerpt)) . "...\n\n";
        }
    }
}
