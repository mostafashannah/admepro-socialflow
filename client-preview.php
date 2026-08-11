<?php
/**
 * client-preview.php — public, no-login preview/approval page for a task
 * sitting in Client Approval. Reached via a QR code / link generated in
 * the app (see PostDetail's "Client Approval Link" section in app.jsx),
 * valid for 24 hours from generation.
 *
 * GET  ?token=xxx   — shows media/caption/hashtags/publish date, an
 *                      Approve button, and a comment box.
 * POST ?token=xxx   — action=approve moves the task to stage 'approved';
 *                      action=comment saves a client-audience comment
 *                      visible in the app's normal comment thread.
 * Both re-validate the token and its 24h expiry server-side on every hit
 * — this file has no session/auth of its own, the token IS the auth.
 */

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function renderShell(string $title, string $body): void {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title><style>'
        . 'body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f7;margin:0;padding:0;color:#1a1a1a}'
        . '.wrap{max-width:520px;margin:0 auto;padding:20px 16px 60px}'
        . '.card{background:#fff;border-radius:14px;padding:18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.06)}'
        . 'h1{font-size:18px;margin:14px 0 4px}'
        . 'img,video{width:100%;border-radius:10px;display:block;margin-bottom:8px;background:#eee}'
        . '.label{font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}'
        . '.cap{white-space:pre-wrap;line-height:1.5;font-size:15px}'
        . '.hash{color:#3b82f6;font-size:13px;margin-top:6px}'
        . '.date{font-size:13px;color:#555}'
        . 'textarea{width:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:8px;padding:10px;font-size:14px;font-family:inherit;min-height:80px}'
        . 'button{width:100%;padding:13px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px}'
        . '.approve{background:#10b981;color:#fff}'
        . '.comment{background:#f1f1f3;color:#333}'
        . '.notice{background:#fff3cd;color:#7a5c00;padding:12px 14px;border-radius:10px;font-size:14px}'
        . '.ok{background:#d1fae5;color:#065f46;padding:14px;border-radius:10px;font-size:15px;font-weight:600;text-align:center}'
        . '</style></head><body><div class="wrap">' . $body . '</div></body></html>';
}

$token = trim($_GET['token'] ?? '');
if (!$token) { http_response_code(400); renderShell('Invalid link', '<div class="notice">This link is missing its access token.</div>'); exit; }

$stmt = $pdo->prepare("SELECT * FROM client_approval_links WHERE token = :t LIMIT 1");
$stmt->execute([':t' => $token]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$link) { http_response_code(404); renderShell('Link not found', '<div class="notice">This link isn\'t valid. Ask your account manager for a new one.</div>'); exit; }

$expired = new DateTime($link['expires_at']) < new DateTime();
if ($expired) { renderShell('Link expired', '<div class="notice">This preview link has expired (links are valid for 24 hours). Ask your account manager to generate a new one.</div>'); exit; }

$postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id LIMIT 1");
$postStmt->execute([':id' => $link['post_id']]);
$post = $postStmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { renderShell('Not found', '<div class="notice">This task no longer exists.</div>'); exit; }

$clientStmt = $pdo->prepare("SELECT name, logo_url FROM clients WHERE id = :id LIMIT 1");
$clientStmt->execute([':id' => $link['client_id'] ?: $post['client_id']]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ---- POST: approve or comment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($link['status'] === 'approved') {
        renderShell('Already approved', '<div class="ok">This was already approved on ' . h(substr($link['responded_at'] ?? '', 0, 16)) . '.</div>');
        exit;
    }
    if ($action === 'approve') {
        $pdo->prepare("UPDATE posts SET stage = 'approved' WHERE id = :id")->execute([':id' => $post['id']]);
        $pdo->prepare("UPDATE client_approval_links SET status = 'approved', responded_at = NOW() WHERE id = :id")->execute([':id' => $link['id']]);
        renderShell('Approved', '<div class="ok">✓ Approved — thank you! Your team has been notified.</div>');
        exit;
    }
    if ($action === 'comment') {
        $text = trim($_POST['comment'] ?? '');
        if ($text !== '') {
            $pdo->prepare(
                "INSERT INTO comments (id, post_id, content, author_name, author_email, type, audience) VALUES (:id, :pid, :content, :aname, :aemail, 'comment', 'client')"
            )->execute([
                ':id' => uuid(), ':pid' => $post['id'], ':content' => $text,
                ':aname' => $client['name'] ?? 'Client', ':aemail' => '',
            ]);
            $pdo->prepare("UPDATE client_approval_links SET status = 'commented', comment = :c, responded_at = NOW() WHERE id = :id")
                ->execute([':c' => $text, ':id' => $link['id']]);
        }
        renderShell('Comment sent', '<div class="ok">✓ Your comment was sent to the team.</div>');
        exit;
    }
    http_response_code(400);
    renderShell('Invalid request', '<div class="notice">Unknown action.</div>');
    exit;
}

// ---- GET: render preview ----
if ($link['status'] === 'approved') {
    renderShell('Already approved', '<div class="ok">✓ This was already approved on ' . h(substr($link['responded_at'] ?? '', 0, 16)) . '. No further action needed.</div>');
    exit;
}

$designAssets = json_decode($post['design_assets'] ?? '[]', true) ?: [];
$designUrls = json_decode($post['design_urls'] ?? '[]', true) ?: [];
$mediaHtml = '';
if ($designAssets) {
    foreach (array_slice($designAssets, 0, 6) as $a) {
        $url = $a['url'] ?? '';
        if (!$url) continue;
        $isVideo = (($a['file_type'] ?? $a['type'] ?? '') === 'video') || preg_match('/\.(mp4|mov|webm|m4v)$/i', $url);
        $mediaHtml .= $isVideo ? '<video src="' . h($url) . '" controls playsinline></video>' : '<img src="' . h($url) . '" loading="lazy">';
    }
} elseif ($designUrls) {
    foreach (array_slice($designUrls, 0, 6) as $url) {
        $isVideo = preg_match('/\.(mp4|mov|webm|m4v)$/i', $url);
        $mediaHtml .= $isVideo ? '<video src="' . h($url) . '" controls playsinline></video>' : '<img src="' . h($url) . '" loading="lazy">';
    }
}
if (!$mediaHtml && $post['carousel_cover']) {
    $mediaHtml = '<img src="' . h($post['carousel_cover']) . '" loading="lazy">';
}

$dateHtml = '';
if (!empty($post['scheduled_date'])) {
    $dateHtml = '<div class="card"><div class="label">Planned Publish Date</div><div class="date">' . h($post['scheduled_date'])
        . (!empty($post['scheduled_time']) ? ' at ' . h($post['scheduled_time']) : '') . '</div></div>';
}

$body = '<div style="text-align:center;padding:18px 0 6px">'
    . (!empty($client['logo_url']) ? '<img src="' . h($client['logo_url']) . '" style="width:56px;height:56px;border-radius:12px;object-fit:cover;margin:0 auto 8px">' : '')
    . '<div style="font-weight:700;font-size:15px;color:#555">' . h($client['name'] ?? '') . '</div>'
    . '<h1>' . h($post['title'] ?? 'Content for your review') . '</h1></div>'
    . '<div class="card">' . $mediaHtml
    . '<div class="label">Caption</div><div class="cap">' . nl2br(h($post['caption'] ?? '(no caption)')) . '</div>'
    . (!empty($post['hashtags']) ? '<div class="hash">' . h($post['hashtags']) . '</div>' : '')
    . '</div>'
    . $dateHtml
    . '<div class="card">'
    . '<form method="post"><input type="hidden" name="action" value="approve">'
    . '<button type="submit" class="approve">✓ Approve</button></form>'
    . '<form method="post" style="margin-top:14px"><input type="hidden" name="action" value="comment">'
    . '<div class="label">Or leave a comment / request changes</div>'
    . '<textarea name="comment" placeholder="Write your feedback…" required></textarea>'
    . '<button type="submit" class="comment">Send Comment</button></form>'
    . '</div>'
    . '<div style="text-align:center;font-size:11px;color:#999;margin-top:10px">This link expires 24 hours after it was sent.</div>';

renderShell($post['title'] ?? 'Content Preview', $body);
