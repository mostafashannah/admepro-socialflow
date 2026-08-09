<?php
// READ-ONLY diagnostic — checking the admin's configured task duration
// settings (task_durations in app_settings) to see if "reel"/"carousel"
// actually get a longer default duration than "image"/"static" (would
// explain more than the expected ~4h of tasks overflowing past 7pm).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$row = $pdo->query("SELECT task_durations, duration_method FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "=== app_settings duration config ===\n" . json_encode($row) . "\n";
