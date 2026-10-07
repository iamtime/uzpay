<?php

/*
| php examples/pdo/demo.php
|
| Creates orders in a fresh SQLite database and pays them the way Payme, Click, Uzum, Paynet and Octo would,
| through the same handlers webhook.php serves. Then Payme cancels the first payment (a refund) while the order
| is not shipped yet.
*/

require __DIR__.'/app.php';

use Uzpay\Sandbox\Emulator;

$file = sys_get_temp_dir().'/uzpay-demo.sqlite';
@unlink($file);
$db = shop_database($file);

$db->exec("INSERT INTO orders (number, total) VALUES ('1001', '125000.00'), ('1002', '48000.00'), ('1003', '73500.00'), ('1004', '19900.00'), ('1005', '250000.00')");
$db->exec("INSERT INTO order_items (order_id, title, price, quantity, mxik_code, package_code, vat_percent) VALUES
    (1, 'Чехол для телефона', '45000', 2, '08517001001000000', '1503426', 12),
    (1, 'Защитное стекло', '35000', 1, '07007001002000000', '1503426', 12),
    (2, 'Наушники', '48000', 1, '08518001001000000', '1503426', 12),
    (3, 'Зарядка', '73500', 1, '08504001001000000', '1503426', 12),
    (4, 'Кабель', '19900', 1, '08544001001000000', '1503426', 12),
    (5, 'Колонка', '250000', 1, '08518002001000000', '1503426', 12)");

$handlers = payment_handlers($db, demo: true); // made-up keys: never outside this demo
$orders = new Orders($db);
$print = function (array $log): void {
    foreach ($log as $step) {
        $error = $step['response']['error'] ?? $step['response']['errorCode'] ?? 0;
        printf("  %-24s %s\n", $step['call'], is_array($error) ? 'ошибка '.$error['code'] : ($error ? 'ошибка '.$error : 'ок'));
    }
};

echo "Payme, заказ 1001:\n";
$print(Emulator::payme($handlers['payme'], 'payme-test-key', 'order_id', $orders->find('1001'), approve: true));

echo "Click, заказ 1002 - не хватило денег, потом оплатил:\n";
$print(Emulator::click($handlers['click'], '100', 'click-secret', $orders->find('1002'), approve: false, signTime: date('Y-m-d H:i:s')));
$print(Emulator::click($handlers['click'], '100', 'click-secret', $orders->find('1002'), approve: true, signTime: date('Y-m-d H:i:s')));

echo "Uzum, заказ 1003:\n";
$print(Emulator::uzum($handlers['uzum'], '300', 'uzum', 'uzum-pass', 'order_id', $orders->find('1003'), approve: true));

echo "Paynet, заказ 1004:\n";
$print(Emulator::paynet($handlers['paynet'], '400', 'paynet', 'paynet-pass', 'order_id', $orders->find('1004'), approve: true));

echo "Octo (Visa), заказ 1005:\n";
$page = $handlers['octo_payments']()->start($orders->find('1005'), 'https://shop.example/orders/1005', 'https://shop.example/octo');
$started = $db->query("SELECT external_id, payload FROM payment_transactions WHERE provider = 'octo'")->fetch();
echo "  страница оплаты          {$page}\n";
$print(Emulator::octo($handlers['octo'], 'octo-unique', $started['external_id'], json_decode($started['payload'], true)['uuid'], '250000.00', approve: true));

echo "Payme отменяет оплату заказа 1001 (возврат):\n";
$paymeId = $db->query("SELECT external_id FROM payment_transactions WHERE provider = 'payme'")->fetchColumn();
$answer = $handlers['payme'](['id' => 1, 'method' => 'CancelTransaction', 'params' => ['id' => $paymeId, 'reason' => 5]], 'Basic '.base64_encode('Paycom:payme-test-key'));
echo '  CancelTransaction        ', isset($answer['error']) ? 'ошибка '.$answer['error']['code'] : 'ок, state '.$answer['result']['state'], "\n\n";

foreach ($db->query('SELECT number, total, status, paid_via FROM orders') as $order) {
    printf("Заказ %s на %s сум: %s%s\n", $order['number'], $order['total'], $order['status'], $order['paid_via'] ? ' ('.$order['paid_via'].')' : '');
}
foreach ($db->query('SELECT provider, external_id, amount, state FROM payment_transactions') as $t) {
    printf("Транзакция %s %s: %s сум, state %d\n", $t['provider'], $t['external_id'], $t['amount'], $t['state']);
}
