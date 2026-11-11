<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * secure-passwords-migrate.php — one-time (safe to re-run): stores a scrambled
 * copy of every readable password still on file and then clears the readable
 * one, for team members and client portal users. Nobody's password changes.
 *
 *   php /var/www/socialflow/secure-passwords-migrate.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
$pdo = authPdo();
foreach (['team_members', 'client_users'] as $t) {
    try { $pdo->query("SELECT password_hash FROM `$t` LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE `$t` ADD COLUMN password_hash VARCHAR(255) NULL"); }
    $rows = $pdo->query("SELECT id, password, password_hash FROM `$t` WHERE password IS NOT NULL AND password <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    $up = $pdo->prepare("UPDATE `$t` SET password_hash = ?, password = '' WHERE id = ?");
    foreach ($rows as $r) {
        // If a newer scrambled one already matches, keep it; otherwise scramble the readable one.
        $hash = (!empty($r['password_hash']) && password_verify($r['password'], $r['password_hash'])) ? $r['password_hash'] : password_hash($r['password'], PASSWORD_DEFAULT);
        $up->execute([$hash, $r['id']]);
        $n++;
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE (password_hash IS NULL OR password_hash = '') AND (password IS NULL OR password = '')")->fetchColumn();
    echo "$t: secured $n password(s); $left account(s) have no password at all (they must use Forgot password).\n";
}
