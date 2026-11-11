<?php
/**
 * auth.php — login / logout / "who am I" for real server-side sessions.
 *
 *   POST /auth.php?action=login   {email, password, mode: "team"|"client"}
 *        → 200 {token, user}  |  401/403/429 {error}
 *   POST /auth.php?action=logout  (X-SF-Session: <token>)
 *   GET  /auth.php?action=me      (X-SF-Session: <token>) → {user_type, user_id, email, role, client_id}
 *
 * The password is checked here, on the server — the browser no longer needs
 * to download the account row (password included) to compare it itself.
 * Mirrors the rules LoginScreen has always applied, so nobody who can log in
 * today is turned away.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function authOut($code, $payload) { http_response_code($code); echo json_encode($payload); exit; }

$action = $_GET['action'] ?? '';

try {
    $pdo = authPdo();

    if ($action === 'me') {
        [$tok] = authCredentials();
        $s = $tok ? authFindSession($tok) : null;
        if (!$s) authOut(401, ['error' => 'Not signed in']);
        authOut(200, ['user_type' => $s['user_type'], 'user_id' => $s['user_id'], 'email' => $s['email'], 'role' => $s['role'], 'client_id' => $s['client_id']]);
    }

    if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // A signed-in team member / client user changes their own password (needs the current one).
        [$tok] = authCredentials();
        $s = $tok ? authFindSession($tok) : null;
        if (!$s || !in_array($s['user_type'], ['team', 'client'], true)) authOut(401, ['error' => 'Not signed in']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $cur = (string)($in['current'] ?? ''); $new = (string)($in['new'] ?? '');
        if (strlen($new) < 8) authOut(400, ['error' => 'New password must be at least 8 characters.']);
        $table = $s['user_type'] === 'team' ? 'team_members' : 'client_users';
        if ($table === 'client_users' && $s['user_id'] === $s['client_id']) authOut(400, ['error' => 'Ask your account manager to change this password.']);
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
        $st->execute([$s['user_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !authPasswordOk($row, $cur)) authOut(403, ['error' => 'Current password is incorrect.']);
        authStoreHash($pdo, $table, $s['user_id'], $new);
        authOut(200, ['ok' => true]);
    }

    if ($action === 'logout') {
        [$tok] = authCredentials();
        if ($tok) authRevokeSession($tok);
        authOut(200, ['ok' => true]);
    }

    if ($action === 'guest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // A guest pass for one public link. It only opens the record the link's token points to.
        require_once __DIR__ . '/guest-policy.php';
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $kind = (string)($in['kind'] ?? '');
        $tok = (string)($in['token'] ?? '');
        $ip = 'ip:' . authClientIp();
        if (authTooManyFails($pdo, $ip)) authOut(429, ['error' => 'Too many attempts. Please try again later.']);
        $rowId = '';
        if ($kind !== 'careers') {
            if (!isset(GUEST_KINDS[$kind]) || strlen($tok) < 8) authOut(400, ['error' => 'Invalid link']);
            [$tbl, $col] = GUEST_KINDS[$kind];
            $st = $pdo->prepare("SELECT id FROM `$tbl` WHERE `$col` = ? LIMIT 1");
            $st->execute([$tok]);
            $rowId = $st->fetchColumn();
            if (!$rowId) { authLogAttempt($pdo, $ip, false); authOut(404, ['error' => 'This link is invalid or has expired.']); }
        }
        authEnsureSchema($pdo);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO auth_sessions (token_hash, user_type, user_id, email, role, client_id, created_at, last_seen_at, expires_at, ip, user_agent)
            VALUES (?, 'guest', ?, NULL, ?, NULL, ?, ?, ?, ?, ?)")
            ->execute([hash('sha256', $token), (string)$rowId, $kind, authNow(), authNow(), authNow(86400), authClientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        authOut(200, ['token' => $token]);
    }

    if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $r = authCheckChallenge($pdo, (string)($in['challenge'] ?? ''), (string)($in['code'] ?? ''));
        if (is_string($r)) authOut(401, ['error' => $r]);
        [$ut, $uid] = $r;
        $st = $pdo->prepare("SELECT * FROM team_members WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || in_array($u['status'] ?? '', ['blocked', 'inactive'], true)) authOut(403, ['error' => 'This account is not active.']);
        $token = authCreateSession($pdo, 'team', $u, $u['role'] ?? null);
        $device = !empty($in['remember']) ? authRememberDevice($pdo, 'team', $u['id']) : null;
        $u2 = $u; unset($u2['password_hash'], $u2['password']);
        foreach ($u2 as $k => $v) { if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) { $d = json_decode($v, true); if (json_last_error() === JSON_ERROR_NONE) $u2[$k] = $d; } }
        authOut(200, ['token' => $token, 'device' => $device, 'user' => array_merge($u2, ['isClient' => false])]);
    }

    if ($action === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $email = trim((string)($in['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) authOut(400, ['error' => 'Enter a valid email address.']);
        // Same limiter as login: stops someone resetting a person's password over and over.
        if (authTooManyFails($pdo, $email)) authOut(429, ['error' => 'Too many attempts. Please wait 15 minutes and try again.']);
        authLogAttempt($pdo, $email, false);
        $found = null; $table = null;
        foreach (['team_members', 'client_users'] as $t) {
            $st = $pdo->prepare("SELECT id, name FROM {$t} WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) { $found = $row; $table = $t; break; }
        }
        if ($found) {
            $temp = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
            authStoreHash($pdo, $table, $found['id'], $temp); // stored scrambled only
            $name = htmlspecialchars($found['name'] ?: explode('@', $email)[0]);
            $html = '<div style="font-family:Montserrat,Arial,sans-serif;max-width:500px;margin:0 auto;padding:32px;background:#fff;border-radius:12px">'
                . '<h2 style="text-align:center;font-size:20px;font-weight:800;color:#111827;margin-bottom:8px">Password Reset</h2>'
                . '<p style="color:#4b5563;font-size:14px;line-height:1.6;text-align:center">Hi ' . $name . ', here is your temporary password:</p>'
                . '<div style="margin:24px auto;text-align:center;background:#f9fafb;border:2px dashed #d1d5db;border-radius:10px;padding:18px 24px"><span style="font-size:26px;font-weight:800;letter-spacing:3px;color:#d90b2c;font-family:monospace">' . $temp . '</span></div>'
                . '<p style="color:#6b7280;font-size:13px;text-align:center;line-height:1.6">Sign in with this temporary password, then update it in your account settings. If you did not ask for this, contact your admin.</p></div>';
            authSendMail($email, 'Your temporary SocialFlow password', $html);
        }
        // Same answer either way, so this can't be used to discover which emails have accounts.
        authOut(200, ['ok' => true]);
    }

    if ($action === 'request_access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) authOut(400, ['error' => 'Name and a valid email are required.']);
        if (authTooManyFails($pdo, 'req:' . $email)) authOut(429, ['error' => 'Too many requests. Please try again later.']);
        authLogAttempt($pdo, 'req:' . $email, false);
        $ex = $pdo->prepare("SELECT status FROM access_requests WHERE email = ? LIMIT 1");
        $ex->execute([$email]);
        if (!$ex->fetch()) {
            $id = bin2hex(random_bytes(16));
            $id = substr($id,0,8).'-'.substr($id,8,4).'-'.substr($id,12,4).'-'.substr($id,16,4).'-'.substr($id,20,12);
            $pdo->prepare("INSERT INTO access_requests (id, name, email, user_type, message, status) VALUES (?, ?, ?, ?, ?, 'pending')")
                ->execute([$id, mb_substr($name, 0, 120), $email, ($in['user_type'] ?? '') === 'client' ? 'client' : 'internal', mb_substr(trim((string)($in['message'] ?? '') . (!empty($in['company']) ? "\nCompany: " . $in['company'] : '')), 0, 2000)]);
            // Tell the admins (taken from the team list, not a fixed address).
            $admins = $pdo->query("SELECT email FROM team_members WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
            if ($admins) {
                $html = '<div style="font-family:Arial,sans-serif;padding:24px"><h2>New access request</h2><p><b>' . htmlspecialchars($name) . '</b> (' . htmlspecialchars($email) . ') asked for access to SocialFlow.</p><p>Review it in SocialFlow → Team → Access Requests.</p></div>';
                authSendMail($admins, 'New Access Request on SocialFlow', $html);
            }
        }
        authOut(200, ['ok' => true]);
    }

    if ($action !== 'login' || $_SERVER['REQUEST_METHOD'] !== 'POST') authOut(400, ['error' => 'Unknown action']);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim((string)($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $mode = ($in['mode'] ?? 'team') === 'client' ? 'client' : 'team';
    if ($email === '') authOut(400, ['error' => 'Enter your email.']);

    if (authTooManyFails($pdo, $email)) authOut(429, ['error' => 'Too many failed attempts. Please wait 15 minutes and try again.']);

    $strip = function (array $row) { unset($row['password_hash'], $row['password']); return $row; };
    $decode = function (array $row) {
        // Same JSON decoding api.php applies, so the user object has the shape the app expects.
        foreach ($row as $k => $v) {
            if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) { $d = json_decode($v, true); if (json_last_error() === JSON_ERROR_NONE) $row[$k] = $d; }
        }
        return $row;
    };

    if ($mode === 'team') {
        $st = $pdo->prepare("SELECT * FROM team_members WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $status = $u['status'] ?? '';
            if ($status === 'blocked') authOut(403, ['error' => 'Your account has been blocked. Contact your admin.']);
            if ($status === 'inactive') authOut(403, ['error' => 'This account is inactive. Contact your admin if you believe this is a mistake.']);
            if ($status === 'invited' || $status === 'pending') authOut(403, ['error' => 'Your account is pending setup. Check your invitation email.']);
            $ok = authPasswordOk($u, $password);
            if ($ok === null) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'No password is set for this account. Use "Forgot password?" to get one.']); }
            if ($ok === false) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'Incorrect password.']); }
            if (empty($u['password_hash']) || !password_verify($password, (string)$u['password_hash'])) authStoreHash($pdo, 'team_members', $u['id'], $password);
            authLogAttempt($pdo, $email, true);
            // Roles that see money/salaries confirm with an emailed code (unless this device is remembered).
            // Emergency switch (config.php): define('DISABLE_2FA', true); — only if code emails stop arriving.
            if (!(defined('DISABLE_2FA') && DISABLE_2FA) && in_array($u['role'] ?? '', AUTH_2FA_ROLES, true) && !authDeviceTrusted($pdo, 'team', $u['id'], $in['device'] ?? '')) {
                $ch = authStartChallenge($pdo, 'team', $u);
                $em = (string)$u['email']; $at = strpos($em, '@');
                authOut(200, ['need_code' => true, 'challenge' => $ch, 'email_hint' => substr($em, 0, 2) . str_repeat('•', max(1, $at - 2)) . substr($em, $at)]);
            }
            $token = authCreateSession($pdo, 'team', $u, $u['role'] ?? null);
            authOut(200, ['token' => $token, 'user' => array_merge($decode($strip($u)), ['isClient' => false])]);
        }
        // Not a team member — say why if they have an access request on file.
        try {
            $rq = $pdo->prepare("SELECT status FROM access_requests WHERE email = ? LIMIT 1");
            $rq->execute([$email]);
            $r = $rq->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['status'] === 'pending') authOut(403, ['error' => 'Your access request is pending admin approval.']);
            if ($r && $r['status'] === 'rejected') authOut(403, ['error' => 'Your access request was rejected. Contact your admin.']);
        } catch (Throwable $e) { /* table missing — fall through */ }
        authLogAttempt($pdo, $email, false);
        authOut(401, ['error' => "Email not found. Request access if you don't have an account."]);
    }

    // ── Client portal ──
    $st = $pdo->prepare("SELECT * FROM client_users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $cu = $st->fetch(PDO::FETCH_ASSOC);
    if ($cu) {
        if (($cu['status'] ?? '') === 'blocked') authOut(403, ['error' => 'Your account has been blocked.']);
        $ok = authPasswordOk($cu, $password);
        if ($ok === null) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'No password is set for this account. Use "Forgot password?" to get one.']); }
        if ($ok === false) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'Invalid credentials. Check your email and password.']); }
        if (empty($cu['password_hash']) || !password_verify($password, (string)$cu['password_hash'])) authStoreHash($pdo, 'client_users', $cu['id'], $password);
        $org = null;
        if (!empty($cu['client_id'])) {
            $c = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
            $c->execute([$cu['client_id']]);
            $org = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        // First successful sign-in completes the invitation.
        try { $pdo->prepare("UPDATE client_users SET status = 'active', last_login = ? WHERE id = ?")->execute([authNow(), $cu['id']]); } catch (Throwable $e) {}
        $cu['status'] = 'active';
        authLogAttempt($pdo, $email, true);
        $role = $cu['role'] ?: 'client_member';
        $token = authCreateSession($pdo, 'client', $cu, $role, $cu['client_id'] ?? null);
        $clientOrg = $org ? $decode($org) : ['name' => $cu['client_name'] ?? '', 'id' => $cu['client_id'] ?? ''];
        unset($clientOrg['portal_password']);
        authOut(200, ['token' => $token, 'user' => array_merge($decode($strip($cu)), ['isClient' => true, 'role' => $role, 'clientOrg' => $clientOrg])]);
    }
    // Legacy: the client record's own email + portal password.
    $c = $pdo->prepare("SELECT * FROM clients WHERE email = ? LIMIT 1");
    $c->execute([$email]);
    $cl = $c->fetch(PDO::FETCH_ASSOC);
    if ($cl && (string)($cl['portal_password'] ?? '') !== '' && hash_equals((string)$cl['portal_password'], $password)) {
        authLogAttempt($pdo, $email, true);
        $token = authCreateSession($pdo, 'client', $cl, 'client_admin', $cl['id']);
        authOut(200, ['token' => $token, 'user' => array_merge($decode($cl), ['isClient' => true, 'role' => 'client_admin'])]);
    }
    authLogAttempt($pdo, $email, false);
    authOut(401, ['error' => 'Invalid credentials. Check your email and password.']);
} catch (Throwable $e) {
    error_log('[auth] ' . $e->getMessage());
    // 500 (not 401) so the app knows to fall back to its legacy sign-in rather than show "wrong password".
    authOut(500, ['error' => 'Sign-in service error']);
}
