<?php

/*
| php examples/checkout-links.php
|
| Where to send the payer. Payme, Click, Uzum and Paynet show their own payment page and come back to return_url;
| the order becomes paid by the callback (see sandbox.php and pdo/), never by this redirect.
| Octo's page is not a fixed link: Octo\Payments::start() asks Octo for it (see sandbox.php).
*/

require __DIR__.'/bootstrap.php';

use Uzpay\Click\Checkout as ClickCheckout;
use Uzpay\Money;
use Uzpay\Payme\Checkout as PaymeCheckout;
use Uzpay\Paynet\Checkout as PaynetCheckout;
use Uzpay\Uzum\Checkout as UzumCheckout;

$order = ['number' => '1042', 'amount' => '125000.50'];
$back = 'https://shop.example/orders/1042';

// Payme: merchant id from the Payme cabinet, the account field set up there ("order_id"), the sum in tiyin.
echo PaymeCheckout::url('5e730e8e0b852a417aa49ceb', 'order_id', $order['number'], Money::tiyin($order['amount']), $back), "\n";
// Payme test cashbox (test.paycom.uz).
echo PaymeCheckout::url('5e730e8e0b852a417aa49ceb', 'order_id', $order['number'], Money::tiyin($order['amount']), $back, test: true), "\n";

// Click: service id and merchant id from the Click cabinet; transaction_param comes back as merchant_trans_id.
echo ClickCheckout::url('12345', '67890', $order['amount'], $order['number'], $back), "\n";

// Uzum Bank: service id from the Uzum cabinet, the account field set up there ("order_id"), the sum in tiyin.
echo UzumCheckout::url('498624684', 'order_id', $order['number'], Money::tiyin($order['amount']), $back), "\n";

// Paynet: opens the shop in the Paynet app with the order number and the sum filled in.
echo PaynetCheckout::url('12345', $order['number'], Money::tiyin($order['amount'])), "\n";
