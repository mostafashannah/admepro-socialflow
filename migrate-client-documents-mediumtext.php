<?php
// Migration — client_documents.content is a plain TEXT column (64KB cap),
// which can't hold a real 500K+ character ChatGPT export. Upgrading to
// MEDIUMTEXT (16MB cap) so the upcoming fix to stop truncating uploads at
// 8000 characters can actually store the full paste instead of silently
// losing everything past the column's own limit too.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$type = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_documents' AND COLUMN_NAME = 'content'")->fetchColumn();
echo "Current type: {$type}\n";
if (strtolower($type) === 'mediumtext' || strtolower($type) === 'longtext') {
    echo "Already large enough — no change needed.\n";
    exit;
}
$pdo->exec("ALTER TABLE client_documents MODIFY content MEDIUMTEXT NULL");
echo "Upgraded content column to MEDIUMTEXT.\n";
