<?php
/** Open pixel (?o=send&s=sig) and click redirect (?c=send&u=url&s=sig). */
require_once __DIR__ . '/growth-lib.php';
try {
    if (isset($_GET['o'])) {
        $id = (int)$_GET['o'];
        if (gCheck('o' . $id, $_GET['s'] ?? '')) gPdo()->prepare("UPDATE mkt_sends SET opened_at = COALESCE(opened_at, NOW()) WHERE id=?")->execute([$id]);
        header('Content-Type: image/gif'); header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
    }
    $id = (int)($_GET['c'] ?? 0); $u = (string)($_GET['u'] ?? '');
    if ($id && preg_match('#^https?://#i', $u) && gCheck('c' . $id . $u, $_GET['s'] ?? '')) {
        gPdo()->prepare("UPDATE mkt_sends SET clicked_at = COALESCE(clicked_at, NOW()), opened_at = COALESCE(opened_at, NOW()) WHERE id=?")->execute([$id]);
        header('Location: ' . $u, true, 302); exit;
    }
} catch (Throwable $e) { error_log('[mkt-track] ' . $e->getMessage()); if (!empty($u) && preg_match('#^https?://#i', $u) && gCheck('c' . ($id ?? 0) . $u, $_GET['s'] ?? '')) { header('Location: ' . $u, true, 302); exit; } }
header('Location: https://admepro.com', true, 302);
