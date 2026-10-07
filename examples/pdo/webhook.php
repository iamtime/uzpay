<?php

/*
| The callback endpoint. Addresses for the providers' cabinets:
|   Payme   https://your.shop/payme
|   Click   https://your.shop/click (Prepare and Complete both)
|   Uzum    https://your.shop/uzum  (Uzum adds /check, /create, /confirm, /reverse, /status)
|   Paynet  https://your.shop/paynet
|   Octo    https://your.shop/octo  (notify_url)
| Locally: php -S 127.0.0.1:8080 examples/pdo/webhook.php
|
| Keys come from the environment (PAYME_MERCHANT_ID, PAYME_KEY, PAYME_TEST_KEY, PAYME_TEST=1 for the sandbox,
| CLICK_SERVICE_ID, CLICK_MERCHANT_ID, CLICK_SECRET_KEY, UZUM_*, PAYNET_*, OCTO_*). A provider without its keys
| answers with an error: the demo keys of demo.php are never used here.
|
| Behind Apache with PHP-FPM / CGI the Authorization header may not reach PHP; pass it on, e.g. in .htaccess:
|   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
| The providers publish the addresses their callbacks come from: allow only those on the firewall.
*/

require __DIR__.'/app.php';

use Uzpay\Octo\Notifications;
use Uzpay\Uzum\Merchant as Uzum;

$handlers = payment_handlers(shop_database());
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$json = json_decode((string) file_get_contents('php://input'), true) ?: [];
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$status = 200;

if (preg_match('#^/uzum/(\w+)$#', (string) $path, $m)) {
    $answer = $handlers['uzum']($m[1], $json, $authorization);
    $status = Uzum::httpStatus($answer);
} else {
    $answer = match ($path) {
        '/payme' => $handlers['payme']($json, $authorization),
        '/click' => $handlers['click']($_POST),
        '/paynet' => $handlers['paynet']($json, $authorization),
        '/octo' => $handlers['octo']($json),
        default => null,
    };
    $status = $path === '/octo' && $answer !== null ? Notifications::httpStatus($answer) : 200;
}

if ($answer === null) {
    http_response_code(404);
    exit;
}

// Payme, Click and Paynet read the error from the body and want HTTP 200.
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($answer, JSON_UNESCAPED_UNICODE);
