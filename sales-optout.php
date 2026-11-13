<?php
/** One-click opt-out from sales outreach (?e=email&s=sig). */
require_once __DIR__ . '/growth-lib.php';
$e = gEmail($_GET['e'] ?? ''); $ok = $e && gCheck('so' . $e, $_GET['s'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($ok && $post) { try { $pdo = gPdo(); $id = gUpsertContact($pdo, ['email' => $e, 'source' => 'sales_agent', 'consent' => 'lead']); $pdo->prepare("UPDATE mkt_contacts SET unsub_email=1, updated_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE sales_prospects p JOIN sales_sends s ON s.prospect_id=p.id SET p.status='rejected' WHERE s.to_email=?")->execute([$e]); } catch (Throwable $x) { error_log('[sales-optout] ' . $x->getMessage()); } }
if ($post && empty($_POST['ui'])) { echo 'ok'; exit; }
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>admepro</title></head><body style="font-family:Arial,sans-serif;background:#f4f4f5;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px"><div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px;max-width:420px;text-align:center">'
    . (!$ok ? '<h2>Link not valid</h2><p style="color:#6b7280">Reply to the email and we\'ll remove you.</p>' : (!$post ? '<h2 style="margin:0 0 8px">Stop emails from admepro?</h2><form method="post"><input type="hidden" name="ui" value="1"/><button style="margin-top:12px;background:#d90b2c;color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:700;cursor:pointer">Yes, stop</button></form>' : '<h2 style="margin:0 0 8px">Done — you won\'t hear from us again.</h2><p style="color:#6b7280">Thanks for letting us know.</p>')) . '</div></body></html>';
