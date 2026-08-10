<?php
// READ-ONLY diagnostic — the general_info extraction only reads the first
// 100,000 characters of the 682,288-character "Chatgpt 1" document. The
// user says a full itemized branch list exists somewhere in the chat.
// Scanning the ENTIRE document (not just the analyzed prefix) for every
// "branch"/city-name mention with generous context, to find where a real
// itemized list actually lives and confirm the 100K-char window is really
// the problem.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$doc = $pdo->prepare("SELECT id, name, content FROM client_documents WHERE name LIKE 'Chatgpt 1%' LIMIT 1");
$doc->execute();
$row = $doc->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "Doc not found.\n"; exit; }
$content = $row['content'];
$len = mb_strlen($content);
echo "Document: {$row['name']}, total length: {$len} chars\n";
echo "Currently-analyzed window: first 100,000 chars (" . round(100000/$len*100, 1) . "% of the document)\n\n";

// Find every occurrence of "branch" (case-insensitive) across the WHOLE doc,
// noting how far into the document (as % ) each one sits.
$terms = ['branch', 'فرع', 'فروع'];
foreach ($terms as $term) {
    $pos = 0;
    $count = 0;
    echo "=== Occurrences of '{$term}' across the FULL document ===\n";
    while (($pos = mb_stripos($content, $term, $pos)) !== false) {
        $count++;
        $pctIn = round($pos / $len * 100, 1);
        $beyondAnalyzed = $pos > 100000 ? " *** BEYOND the 100K analyzed window ***" : "";
        if ($count <= 30) {
            $excerpt = trim(str_replace("\n", " ", mb_substr($content, max(0, $pos - 60), 200)));
            echo "  [{$pctIn}% into doc, char {$pos}{$beyondAnalyzed}] ...{$excerpt}...\n";
        }
        $pos += mb_strlen($term);
    }
    echo "  Total occurrences: {$count}\n\n";
}
