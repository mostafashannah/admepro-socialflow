<?php
/** Welcome-email tracking. Open pixel (?o=id&s=sig) and click redirect (?c=id&u=url&l=label&s=sig). */
require_once __DIR__ . '/growth-lib.php';
try {
    if (isset($_GET['o'])) {
        $id = (int)$_GET['o'];
        if (gCheck('wo' . $id, $_GET['s'] ?? '')) gPdo()->prepare("UPDATE sales_welcome SET opened_at = COALESCE(opened_at, NOW()), open_count = open_count + 1 WHERE id=?")->execute([$id]);
        header('Content-Type: image/gif'); header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
    }
    $id = (int)($_GET['c'] ?? 0); $u = (string)($_GET['u'] ?? '');
    if ($id && preg_match('#^https?://#i', $u) && gCheck('wc' . $id . $u, $_GET['s'] ?? '')) {
        gPdo()->prepare("UPDATE sales_welcome SET clicked_at = COALESCE(clicked_at, NOW()), opened_at = COALESCE(opened_at, NOW()), click_count = click_count + 1, last_click = ? WHERE id=?")
            ->execute([mb_substr((string)($_GET['l'] ?? ''), 0, 40), $id]);
        header('Location: ' . $u, true, 302); exit;
    }
} catch (Throwable $e) { error_log('[sales-track] ' . $e->getMessage()); if (!empty($u) && preg_match('#^https?://#i', $u) && gCheck('wc' . ($id ?? 0) . $u, $_GET['s'] ?? '')) { header('Location: ' . $u, true, 302); exit; } }
header('Location: https://admepro.com', true, 302);
