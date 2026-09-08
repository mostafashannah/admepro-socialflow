<?php
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$cols = $pdo->query("SHOW FULL COLUMNS FROM client_documents")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "{$c['Field']}: {$c['Type']}\n";
