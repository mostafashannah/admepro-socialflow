<?php
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$cols = $pdo->query("SHOW COLUMNS FROM app_settings")->fetchAll(PDO::FETCH_COLUMN);
echo "=== app_settings columns ===\n" . implode(", ", $cols) . "\n";
$row = $pdo->query("SELECT * FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
foreach (['task_durations','duration_method','duration_cfg','task_duration_config'] as $c) {
    if (isset($row[$c])) echo "\n=== $c ===\n" . $row[$c] . "\n";
}
