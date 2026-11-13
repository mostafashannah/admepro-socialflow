<?php
/** One-click unsubscribe (?id=send&s=sig). GET shows a confirmation page; POST (mail clients' one-click) unsubscribes directly. */
require_once __DIR__ . '/growth-lib.php';
$id = (int)($_GET['id'] ?? 0); $ok = $id && gCheck('u' . $id, $_GET['s'] ?? '');
$done = false;
if ($ok && ($_SERVER['REQUEST_METHOD'] === 'POST')) {
    try {
        $pdo = gPdo();
        $pdo->prepare("UPDATE mkt_contacts k JOIN mkt_sends s ON s.contact_id=k.id SET k.unsub_email=1, k.updated_at=NOW() WHERE s.id=?")->execute([$id]);
        $pdo->prepare("UPDATE mkt_sends SET unsub_at = COALESCE(unsub_at, NOW()) WHERE id=?")->execute([$id]);
        $done = true;
    } catch (Throwable $e) { error_log('[mkt-unsub] ' . $e->getMessage()); }
    if (empty($_POST['ui'])) { http_response_code(200); echo 'ok'; exit; }
}
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Unsubscribe · admepro</title>
<style>body{margin:0;font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f4f4f5;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}.c{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px;max-width:420px;text-align:center}h1{font-size:20px;margin:0 0 8px}p{color:#6b7280;font-size:14px;line-height:1.6}button{margin-top:14px;background:#d90b2c;color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:700;font-size:14px;cursor:pointer}</style></head>
<body><div class="c"><img src="https://admepro.com/wp-content/uploads/2024/10/adme-p2.png" alt="admepro" style="height:26px"/>
<?php if (!$ok): ?><h1>Link not valid</h1><p>This unsubscribe link is broken or expired. Reply to any of our emails and we'll remove you.</p>
<?php elseif ($done): ?><h1>You're unsubscribed</h1><p>You won't get admepro newsletters anymore. Sorry to see you go.</p>
<?php else: ?><h1>Unsubscribe?</h1><p>You'll stop receiving admepro newsletters.</p><form method="post"><input type="hidden" name="ui" value="1"/><button type="submit">Unsubscribe me</button></form>
<?php endif; ?></div></body></html>
