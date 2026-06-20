<?php
// ================================================================
// SocialFlow — Web Push send proxy
// Loads the recipient's stored subscription(s) from MySQL and sends
// a real push via the Web Push protocol (works even when the app/
// browser is fully closed), using minishlink/web-push + our VAPID keypair.
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$body = json_decode(file_get_contents("php://input"), true);
if(!$body || !isset($body['to_email'], $body['title'], $body['body'])){
  http_response_code(400);
  echo json_encode(["error"=>"Missing required fields: to_email, title, body"]);
  exit;
}

$toEmail = $body['to_email'];
$title   = $body['title'];
$msgBody = $body['body'];
$url     = $body['url'] ?? '/';

$pdo = new PDO(
  'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_email = :email");
$stmt->execute([':email' => $toEmail]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(!$subs){
  echo json_encode(["sent"=>0, "message"=>"No push subscription for this user"]);
  exit;
}

$webPush = new WebPush([
  'VAPID' => [
    'subject'    => VAPID_SUBJECT,
    'publicKey'  => VAPID_PUBLIC_KEY,
    'privateKey' => VAPID_PRIVATE_KEY,
  ],
]);

$payload = json_encode(["title"=>$title, "body"=>$msgBody, "url"=>$url]);
$sent = 0;
$stale = [];
foreach($subs as $row){
  $sub = Subscription::create([
    'endpoint' => $row['endpoint'],
    'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
  ]);
  $webPush->queueNotification($sub, $payload);
}

foreach($webPush->flush() as $report){
  if($report->isSuccess()) $sent++;
  elseif($report->isSubscriptionExpired()) $stale[] = $report->getRequest()->getUri()->__toString();
}

foreach($stale as $endpoint){
  $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = :e");
  $del->execute([':e' => $endpoint]);
}

echo json_encode(["sent"=>$sent, "total"=>count($subs)]);
