<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// One-time setup: makes the agency's WhatsApp line (the number already connected as WA_PHONE_ID in config.php) show up as a
// WhatsApp connection of the client "admepro", so the Community inbox can send replies and the reply bot can answer on it.
// No token is typed or printed: the values come from config.php.
//   php wa-agency-setup.php          creates the connection if it is missing (does nothing if it already exists)
// ================================================================
require_once __DIR__ . '/config.php';
if (!defined('WA_PHONE_ID') || !WA_PHONE_ID || !defined('WA_ACCESS_TOKEN') || !WA_ACCESS_TOKEN) { echo "WA_PHONE_ID / WA_ACCESS_TOKEN are not set in config.php\n"; exit(1); }
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$c = $pdo->query("SELECT id, name FROM clients WHERE LOWER(TRIM(name)) = 'admepro' ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo "There is no client named \"admepro\". Create it in Clients first.\n"; exit(1); }
$q = $pdo->prepare("SELECT id, status FROM integrations WHERE app_key = 'whatsapp' AND client_id = :c LIMIT 1"); $q->execute([':c' => $c['id']]);
if ($row = $q->fetch(PDO::FETCH_ASSOC)) { echo "admepro already has a WhatsApp connection (status: {$row['status']}). Nothing changed.\n"; exit(0); }
$pdo->prepare("INSERT INTO integrations (id, name, app_key, app_category, `trigger`, action, status, credentials, config, client_id, client_name, created_by)
               VALUES (UUID(), 'WhatsApp — admepro line', 'whatsapp', 'social', 'Message received', 'Reply from the inbox', 'active', :cr, '{}', :cid, :cn, 'setup-script')")
    ->execute([':cr' => json_encode(['phone_id' => (string)WA_PHONE_ID, 'access_token' => (string)WA_ACCESS_TOKEN], JSON_UNESCAPED_SLASHES), ':cid' => $c['id'], ':cn' => $c['name']]);
echo "Created the WhatsApp connection for \"{$c['name']}\" on Phone Number ID " . WA_PHONE_ID . ".\n\nNext, in SocialFlow:\n";
echo "  1. Clients → admepro → Settings → Reply Bot: switch it ON, tick the WhatsApp channel, and choose \"review\" (drafts for a person to approve) to start.\n";
echo "  2. Send a test message from a phone that is NOT saved in a team profile: it should appear in the Community inbox as a draft/needs-human thread.\n";
