<?php
// Migration — Client Brain is core infrastructure per explicit direction:
// no artificial limits on what it can hold. general_info/context_file/dos/
// donts/target_audience/summary/content_preferences are currently plain
// TEXT (65KB cap) — upgrading to MEDIUMTEXT (16MB) so a genuinely large
// amount of saved knowledge never silently hits a ceiling, matching the
// same upgrade already done for client_documents.content.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cols = ['general_info', 'context_file', 'dos', 'donts', 'target_audience', 'summary', 'content_preferences', 'industry_context'];
$existing = $pdo->query("SHOW COLUMNS FROM client_knowledge")->fetchAll(PDO::FETCH_ASSOC);
$existingTypes = [];
foreach ($existing as $c) $existingTypes[$c['Field']] = $c['Type'];

foreach ($cols as $col) {
    if (!isset($existingTypes[$col])) { echo "Column '{$col}' doesn't exist — skipping.\n"; continue; }
    if (stripos($existingTypes[$col], 'mediumtext') !== false) { echo "'{$col}' already MEDIUMTEXT — skipping.\n"; continue; }
    $pdo->exec("ALTER TABLE client_knowledge MODIFY COLUMN `$col` MEDIUMTEXT NULL");
    echo "Upgraded '{$col}' ({$existingTypes[$col]} -> MEDIUMTEXT).\n";
}

// Same for client_memory.value — currently TEXT (65KB), same upgrade for
// the same reason.
$memCols = $pdo->query("SHOW COLUMNS FROM client_memory")->fetchAll(PDO::FETCH_ASSOC);
foreach ($memCols as $c) {
    if ($c['Field'] === 'value' && stripos($c['Type'], 'mediumtext') === false) {
        $pdo->exec("ALTER TABLE client_memory MODIFY COLUMN `value` MEDIUMTEXT NULL");
        echo "Upgraded client_memory.value ({$c['Type']} -> MEDIUMTEXT).\n";
    }
}

echo "\nDone.\n";
