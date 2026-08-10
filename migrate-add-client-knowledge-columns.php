<?php
// Migration — client_knowledge is missing 4 columns the app has always
// tried to write to (context_file, dos, donts, target_audience): the
// running "deep notes" log from every doc upload, and the ChatGPT-import-
// specific brand fields. The backend silently dropped these fields on
// every write with no error, so this data was NEVER actually persisted —
// only the structured summary/tone/keywords/priorities fields (which do
// have real columns) ever made it to the DB. This explains why
// clientBrainBlock's context_file read was always empty regardless of the
// earlier slice(-800) fix — there was nothing there to read.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$existing = $pdo->query("SHOW COLUMNS FROM client_knowledge")->fetchAll(PDO::FETCH_COLUMN);
echo "Existing columns: " . implode(", ", $existing) . "\n\n";

$toAdd = [
    'context_file'    => "ALTER TABLE client_knowledge ADD COLUMN context_file MEDIUMTEXT NULL",
    'dos'             => "ALTER TABLE client_knowledge ADD COLUMN dos TEXT NULL",
    'donts'           => "ALTER TABLE client_knowledge ADD COLUMN donts TEXT NULL",
    'target_audience' => "ALTER TABLE client_knowledge ADD COLUMN target_audience TEXT NULL",
];

foreach ($toAdd as $col => $sql) {
    if (in_array($col, $existing, true)) {
        echo "Column '{$col}' already exists — skipping.\n";
        continue;
    }
    $pdo->exec($sql);
    echo "Added column '{$col}'.\n";
}

echo "\nDone. Columns now: " . implode(", ", $pdo->query("SHOW COLUMNS FROM client_knowledge")->fetchAll(PDO::FETCH_COLUMN)) . "\n";
