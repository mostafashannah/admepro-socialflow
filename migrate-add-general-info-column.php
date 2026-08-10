<?php
// Migration — adds general_info to client_knowledge: contacts, locations/
// branches, addresses, phone numbers, and other general facts that don't
// fit the existing structured fields (tone/keywords/priorities/etc).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$existing = $pdo->query("SHOW COLUMNS FROM client_knowledge")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('general_info', $existing, true)) {
    echo "Column 'general_info' already exists — skipping.\n";
} else {
    $pdo->exec("ALTER TABLE client_knowledge ADD COLUMN general_info TEXT NULL");
    echo "Added column 'general_info'.\n";
}
