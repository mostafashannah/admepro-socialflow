<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$admins = $pdo->query("SELECT name, email, whatsapp_number FROM team_members WHERE role IN ('admin','account_manager') AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
echo "=== ADMINS/AMs WITH WHATSAPP ===\n";
print_r($admins);

$clients = $pdo->query("SELECT id, name, account_manager_id FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== CLIENTS + RAW account_manager_id ===\n";
foreach ($clients as $c) {
    echo "{$c['name']}: account_manager_id = " . var_export($c['account_manager_id'], true) . "\n";
}

// Simulate addFinding for first client
echo "\n=== TEST: clientAlertRecipients for first client ===\n";
$client = $clients[0];

function clientAlertRecipients(PDO $pdo, array $client, array $admins): array {
    $recipients = [];
    if (!empty($client['account_manager_id'])) {
        $amIds = json_decode($client['account_manager_id'], true);
        if (!is_array($amIds)) $amIds = [$client['account_manager_id']];
        foreach ($amIds as $amId) {
            $am = $pdo->prepare("SELECT email, whatsapp_number FROM team_members WHERE id = :id");
            $am->execute([':id' => $amId]);
            if ($row = $am->fetch(PDO::FETCH_ASSOC)) $recipients[] = $row;
        }
    }
    foreach ($admins as $a) $recipients[] = $a;
    $seen = []; $out = [];
    foreach ($recipients as $r) {
        if (empty($r['email']) || isset($seen[$r['email']])) continue;
        $seen[$r['email']] = true;
        $out[] = $r;
    }
    return $out;
}

$recipients = clientAlertRecipients($pdo, $client, $admins);
echo "Recipients for '{$client['name']}':\n";
print_r($recipients);

// Test sendWhatsAppReply directly
echo "\n=== TEST: sendWhatsAppReply to Monay ===\n";
$result = sendWhatsAppReply('+201112311454', 'Mai test message — system check ✅');
echo "Result: ";
var_dump($result);
