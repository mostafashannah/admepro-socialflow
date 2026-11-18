<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// Creates (or resets) a Meta App Review test login:
//   php create-reviewer.php            -> create / reset password
//   php create-reviewer.php remove     -> delete the reviewer account after review
require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$email = 'meta-reviewer@admepro.com';
if (($argv[1] ?? '') === 'remove') { $pdo->prepare("DELETE FROM team_members WHERE email = ?")->execute([$email]); echo "Removed $email\n"; exit; }
$pw = 'Rv-' . bin2hex(random_bytes(5)) . '!A9';
$hash = password_hash($pw, PASSWORD_DEFAULT);
$st = $pdo->prepare("SELECT id FROM team_members WHERE email = ?"); $st->execute([$email]); $id = $st->fetchColumn();
if ($id) { $pdo->prepare("UPDATE team_members SET password_hash = ?, status = 'active' WHERE id = ?")->execute([$hash, $id]); }
else { $pdo->prepare("INSERT INTO team_members (id, name, email, role, status, password_hash) VALUES (UUID(), 'Meta Reviewer', ?, 'admin', 'active', ?)")->execute([$email, $hash]); }
echo "URL:      https://socialflow.admepro.com\nEmail:    $email\nPassword: $pw\n";
