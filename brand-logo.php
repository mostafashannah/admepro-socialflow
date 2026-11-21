<?php
/** Serves one of the agency logos (Settings → Branding → Logo Versions) as a normal image URL, so emails can show it. Logos are public by nature. */
require_once __DIR__ . '/growth-lib.php';
$slot = (string)($_GET['slot'] ?? '');
if (!in_array($slot, ['secondary_logo', 'light_logo', 'primary_logo', 'icon_logo', 'dark_logo'], true)) { http_response_code(404); exit; }
try { $v = (string)gPdo()->query("SELECT `$slot` FROM branding_assets WHERE setting_key='agency_branding' LIMIT 1")->fetchColumn(); } catch (Throwable $e) { $v = ''; }
if ($v === '') { http_response_code(404); exit; }
if (preg_match('#^https?://#i', $v)) { header('Location: ' . $v, true, 302); exit; }
if (!preg_match('#^data:(image/(?:png|jpe?g|gif|webp|svg\+xml));base64,(.+)$#s', $v, $m)) { http_response_code(404); exit; }
$bin = base64_decode($m[2], true); if ($bin === false || $bin === '') { http_response_code(404); exit; }
header('Content-Type: ' . $m[1]); header('Cache-Control: public, max-age=86400'); header('Content-Length: ' . strlen($bin));
echo $bin;
