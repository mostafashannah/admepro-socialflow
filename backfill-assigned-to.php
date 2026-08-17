<?php
// One-off: design_assigned_to was missing from SB_SCHEMA.posts (the
// client-side field whitelist ue() sanitizes every save against), so it
// was silently stripped on every save and never actually reached the
// database — meaning any post currently sitting in the "design" stage
// right now has a NULL design_assigned_to, even though it really does
// have a designer working on it (assigned_to).
//
// That breaks wasOwnerOf() the moment one of these posts moves forward
// (e.g. to Design Review) — assigned_to gets reassigned to the AM at that
// point, and with design_assigned_to still NULL there's no remaining
// trace the designer ever owned it, so it vanishes off their Timeline
// instead of showing as a finished, green block. This backfills
// design_assigned_to (and content_assigned_to, same bug class, belt and
// suspenders) from the CURRENT assigned_to for any post presently sitting
// in its own owned stage — so the very next stage move onward correctly
// preserves who did the work.
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$design = $pdo->exec(
    "UPDATE posts SET design_assigned_to = assigned_to
     WHERE stage = 'design' AND assigned_to IS NOT NULL AND assigned_to != ''
       AND (design_assigned_to IS NULL OR design_assigned_to = '')"
);

$content = $pdo->exec(
    "UPDATE posts SET content_assigned_to = assigned_to
     WHERE stage = 'content_creation' AND assigned_to IS NOT NULL AND assigned_to != ''
       AND (content_assigned_to IS NULL OR content_assigned_to = '')"
);

echo json_encode(['ok' => true, 'design_backfilled' => $design, 'content_backfilled' => $content]) . "\n";
