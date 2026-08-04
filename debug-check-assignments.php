<?php
// READ-ONLY diagnostic — run once, then delete. Checks whether specific
// post_ids referenced by stage-change comments actually still exist in
// the posts table (and if so, what their current assignment fields are).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ids = [
    '92dc8105-a57e-4803-87d9-0934d88a4f99',
    'f06ed290-acfb-4e5d-8714-7b99c04fa945',
    '2db655d4-7762-4526-bd60-dcbe8e150f3c',
    '4e3c8572-edbc-4c2b-b831-dc6d561e6cf9',
    'a84d8f5b-d0a6-4940-bb8a-85c2342eef05',
    '8270bece-9079-4fe3-bf7b-53087892a057',
    '5fff73a1-a9ba-48e7-bf45-7184e103ae86',
    '06fc784f-743e-439d-b38c-ba1b29224c11',
    'local_1785755227286_od0pb',
];
$stmt = $pdo->prepare("SELECT id, title, stage, platform, assigned_to, content_assigned_to, design_assigned_to FROM posts WHERE id = ?");
foreach ($ids as $id) {
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $id . ": " . ($row ? json_encode($row) : "NOT FOUND") . "\n";
}
