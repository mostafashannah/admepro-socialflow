<?php
// READ-ONLY diagnostic — checks Asma's client_users row against the real
// SLVR client record and contact_reports, to see why her portal shows no
// contact reports (the portal filters purely by client_id + client_visible_at,
// same list every login on that client sees).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cu = $pdo->prepare("SELECT id, name, email, client_id, client_name, status FROM client_users WHERE name LIKE '%Asma%' OR email LIKE '%asma%'");
$cu->execute();
$asmaRows = $cu->fetchAll(PDO::FETCH_ASSOC);
echo "=== Asma's client_users row(s) ===\n";
foreach ($asmaRows as $r) { echo json_encode($r) . "\n"; }

$slvr = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%SLVR%'")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Client record(s) matching SLVR ===\n";
foreach ($slvr as $r) { echo json_encode($r) . "\n"; }

foreach ($slvr as $client) {
    $reports = $pdo->prepare("SELECT id, meeting_date, client_visible_at, created_at FROM contact_reports WHERE client_id = ? ORDER BY created_at DESC LIMIT 10");
    $reports->execute([$client['id']]);
    $rows = $reports->fetchAll(PDO::FETCH_ASSOC);
    echo "\n=== Contact reports for client_id={$client['id']} ({$client['name']}) ===\n";
    foreach ($rows as $r) { echo json_encode($r) . "\n"; }
    if (!$rows) echo "(none)\n";
}
