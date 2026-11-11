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

    if ($action === 'logout') {
        [$tok] = authCredentials();
        if ($tok) authRevokeSession($tok);
        authOut(200, ['ok' => true]);
    }

    if ($action !== 'login' || $_SERVER['REQUEST_METHOD'] !== 'POST') authOut(400, ['error' => 'Unknown action']);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim((string)($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $mode = ($in['mode'] ?? 'team') === 'client' ? 'client' : 'team';
    if ($email === '') authOut(400, ['error' => 'Enter your email.']);

    if (authTooManyFails($pdo, $email)) authOut(429, ['error' => 'Too many failed attempts. Please wait 15 minutes and try again.']);

    $strip = function (array $row) { unset($row['password_hash']); return $row; };
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
            if ($ok === false) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'Incorrect password.']); }
            // $ok === null: no password on file — allowed, exactly as before (stage 1 changes no behaviour).
            if ($ok === true) authStoreHash($pdo, 'team_members', $u['id'], $password);
            authLogAttempt($pdo, $email, true);
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
        if ($ok === false) { authLogAttempt($pdo, $email, false); authOut(401, ['error' => 'Invalid credentials. Check your email and password.']); }
        if ($ok === true) authStoreHash($pdo, 'client_users', $cu['id'], $password);
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
