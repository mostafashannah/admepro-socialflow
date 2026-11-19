<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// Start Mai's link report for ONE person right now (for testing, ignores the usual time window).
//   php mai-form-test.php "haneen" morning      morning | eod | director
//   php mai-form-test.php "monay" director
// It sends the real WhatsApp message with the link to that person and prints the link.
// If that person already has a report for today that is NOT completed, it is replaced; a completed one is kept (add `force` to replace it).
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-form-lib.php';
$name = $argv[1] ?? ''; $type = $argv[2] ?? ''; $force = in_array('force', $argv, true);
if ($name === '' || !in_array($type, ['morning', 'eod', 'director'], true)) { echo "Usage: php mai-form-test.php \"name\" morning|eod|director [force]\n"; exit(1); }
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!maiFormReady($pdo)) { echo "The migration has not been run yet: mysql -u root -p socialflow < vps-migration/migration-mai-form.sql\n"; exit(1); }
$q = $pdo->prepare("SELECT id, name, email, role, whatsapp_number, mai_checkin_config FROM team_members WHERE name LIKE :n AND status = 'active'");
$q->execute([':n' => '%' . $name . '%']);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1) { echo count($rows) . " people match \"{$name}\": " . implode(', ', array_column($rows, 'name')) . "\nBe more specific.\n"; exit(1); }
$am = $rows[0];
if (empty($am['whatsapp_number'])) { echo "{$am['name']} has no WhatsApp number saved, so Mai cannot message them.\n"; exit(1); }
$ex = $pdo->prepare("SELECT id, status FROM mai_report_sessions WHERE account_manager_id = :id AND report_type = :t AND report_date = CURDATE()");
$ex->execute([':id' => $am['id'], ':t' => $type]);
if ($old = $ex->fetch(PDO::FETCH_ASSOC)) {
    if ($old['status'] === 'completed' && !$force) { echo "{$am['name']} already completed today's {$type} report. Add the word force to replace it.\n"; exit(1); }
    $pdo->prepare("DELETE FROM mai_report_sessions WHERE id = :id")->execute([':id' => $old['id']]);
    echo "Replaced today's {$old['status']} {$type} report.\n";
}
$id = maiStartFormSession($pdo, $am, $type);
if (!$id) { echo "Not started (the person has switched off Mai report messages, or has nothing to report on).\n"; exit(1); }
$tok = $pdo->prepare("SELECT token FROM mai_report_sessions WHERE id = :id"); $tok->execute([':id' => $id]);
echo "Sent to {$am['name']} on WhatsApp. Link: " . maiFormLink((string)$tok->fetchColumn()) . "\n";
