<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// One-time (and re-runnable) cleanup of admepro's own Leads center — nothing is deleted, rows are only re-labelled:
//   php leads-cleanup.php           dry run: prints what WOULD change
//   php leads-cleanup.php apply     writes the changes
// Rules: a lead that came from a social platform (Instagram, Facebook, Messenger, WhatsApp, comments, TikTok, X, LinkedIn)
// must have a phone number, otherwise category = no_contact. A social lead that the AI reads as a job seeker, a service
// provider pitching us, or noise is re-labelled hiring / service_provider / not_lead. Only category "lead" shows in the
// Leads center. Undo a row by setting its category back to 'lead'.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reply-bot-lib.php';
$apply = (($argv[1] ?? '') === 'apply');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$SOCIAL = ['instagram', 'facebook', 'messenger', 'whatsapp', 'fb_comment', 'ig_comment', 'li_comment', 'tiktok', 'twitter', 'x', 'linkedin', 'social'];
$ownIds = $pdo->query("SELECT id FROM clients WHERE LOWER(TRIM(name)) = 'admepro'")->fetchAll(PDO::FETCH_COLUMN);
$rows = $pdo->query("SELECT id, name, phone, source, notes, category, client_id, client_name FROM leads WHERE (category IS NULL OR category = '' OR category = 'lead') ORDER BY id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
$n = ['checked' => 0, 'no_contact' => 0, 'hiring' => 0, 'service_provider' => 0, 'not_lead' => 0, 'kept' => 0, 'ai_calls' => 0];
$set = $pdo->prepare("UPDATE leads SET category = ? WHERE id = ?");
$log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Lead re-labelled', 'leads', ?, 'success', 'cleanup')");
foreach ($rows as $r) {
    // admepro's own leads only (client leads live in each client's Leads tab)
    $own = $r['client_name'] ? preg_match('/^admepro$/i', trim($r['client_name'])) : ($r['client_id'] ? in_array($r['client_id'], $ownIds, true) : !preg_match("/ on (.{1,60}?)['’]s account/i", (string)$r['notes']));
    if (!$own) continue;
    $src = strtolower(trim((string)$r['source']));
    if (!in_array($src, $SOCIAL, true)) continue;
    $n['checked']++;
    $digits = preg_replace('/\D/', '', (string)$r['phone']);
    $new = null;
    if (strlen($digits) < 7) $new = 'no_contact';
    elseif ($n['ai_calls'] < 250) {
        $text = trim(preg_replace('/src_id:\S+/', '', (string)$r['notes']));
        if (mb_strlen($text) > 20) {
            $n['ai_calls']++;
            $cls = classifyClientContact('admepro', $text);
            if ($cls && $cls['category'] === 'hiring') $new = 'hiring';
            elseif ($cls && $cls['category'] === 'service_provider') $new = 'service_provider';
            elseif ($cls && $cls['category'] === 'other') $new = 'not_lead';
        }
    }
    if (!$new) { $n['kept']++; continue; }
    $n[$new]++;
    echo sprintf("#%s %-28s %-10s -> %s\n", $r['id'], mb_substr((string)$r['name'], 0, 28), $src, $new);
    if ($apply) { $set->execute([$new, $r['id']]); $log->execute(["lead #{$r['id']} ({$r['name']}, $src) -> $new"]); }
}
echo "\n" . ($apply ? 'APPLIED' : 'DRY RUN (nothing changed)') . ': ' . json_encode($n) . "\n";
if (!$apply) echo "Run again with 'apply' to write these changes.\n";
