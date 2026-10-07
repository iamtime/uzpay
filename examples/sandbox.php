<?php

/*
| php examples/sandbox.php
|
| A whole payment without the providers or a database: orders and transactions in memory,
| the emulator plays the provider and sends its calls, signed with our keys, to the same handler
| a real callback reaches.
| The same Emulator calls work on a test page of your shop ("pay" / "decline" buttons) or in your tests.
*/

require __DIR__.'/bootstrap.php';

use Uzpay\Click\Shop;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\PayableSource;
use Uzpay\Fiscal\Receipt;
use Uzpay\Octo\Client as OctoClient;
use Uzpay\Octo\Notifications as OctoNotifications;
use Uzpay\Octo\Payments as OctoPayments;
use Uzpay\PayableState;
use Uzpay\Payme\Merchant;
use Uzpay\Paynet\Merchant as PaynetMerchant;
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\FakeOcto;
use Uzpay\Testing\InMemoryTransactions;
use Uzpay\Uzum\Merchant as UzumMerchant;

/** An order in memory. In an app this is your Order model. */
final class Order implements Payable
{
    public PayableState $state = PayableState::Open;

    public ?string $paidVia = null;

    public function __construct(public string $number, public string $total) {}

    public function paymentKey(): string
    {
        return $this->number;
    }

    public function paymentAmount(): string
    {
        return $this->total;
    }

    public function paymentState(): PayableState
    {
        return $this->state;
    }

    public function markPaidVia(string $provider): void
    {
        $this->state = PayableState::Paid;
        $this->paidVia = $provider;
    }

    public function canRefund(): bool
    {
        return true;
    }

    public function markRefunded(): void
    {
        $this->state = PayableState::Closed;
    }

    public function fiscalDetail(): array
    {
        return Receipt::make()->item('Заказ №'.$this->number, $this->total, 1, '08517001001000000', '1503426', 12)->toArray();
    }
}

final class Orders implements PayableSource
{
    /** @param array<string, Order> $orders */
    public function __construct(private array $orders) {}

    public function accountKey(): string
    {
        return 'order_id';
    }

    public function find(string $key): ?Payable
    {
        return $this->orders[$key] ?? null;
    }
}

final class Keys implements Gateway
{
    /** @param array<string, string> $keys */
    public function __construct(private array $keys, private bool $test = true) {}

    public function credential(string $key): ?string
    {
        return $this->keys[$key] ?? null;
    }

    public function testMode(): bool
    {
        return $this->test;
    }
}

$orders = new Orders([
    '1001' => $first = new Order('1001', '125000'),
    '1002' => $second = new Order('1002', '48000'),
    '1003' => $third = new Order('1003', '73500'),
    '1004' => $fourth = new Order('1004', '19900'),
    '1005' => $fifth = new Order('1005', '250000'),
]);
$store = new InMemoryTransactions;

$payme = new Keys(['merchant_id' => 'demo', 'test_key' => 'payme-test-key']);
$merchant = new Merchant($store, $orders);
$log = Emulator::payme(fn (array $body, string $auth) => $merchant->handle($body, $auth, $payme), 'payme-test-key', 'order_id', $first, approve: true);

$click = new Keys(['service_id' => '100', 'merchant_id' => '200', 'secret_key' => 'click-secret']);
$shop = new Shop($store, $orders);
$send = fn (array $params) => $shop->handle($params, $click);
$log = [...$log, ...Emulator::click($send, '100', 'click-secret', $second, approve: false, signTime: date('Y-m-d H:i:s'))];
$log = [...$log, ...Emulator::click($send, '100', 'click-secret', $second, approve: true, signTime: date('Y-m-d H:i:s'))];

// Uzum calls <url>/check, /create, /confirm: the action is the last part of the path.
$uzum = new Keys(['service_id' => '300', 'login' => 'uzum', 'password' => 'uzum-pass']);
$uzumMerchant = new UzumMerchant($store, $orders);
$log = [...$log, ...Emulator::uzum(fn (string $action, array $body, string $auth) => $uzumMerchant->handle($action, $body, $auth, $uzum), '300', 'uzum', 'uzum-pass', 'order_id', $third, approve: true)];

// Paynet takes the money first and calls PerformTransaction once.
$paynet = new Keys(['service_id' => '400', 'login' => 'paynet', 'password' => 'paynet-pass']);
$paynetMerchant = new PaynetMerchant($store, $orders);
$log = [...$log, ...Emulator::paynet(fn (array $body, string $auth) => $paynetMerchant->handle($body, $auth, $paynet), '400', 'paynet', 'paynet-pass', 'order_id', $fourth, approve: true)];

// Octo (Visa, Mastercard): we ask Octo for the payment page, then Octo notifies; FakeOcto stands in for its API.
$octo = new Keys(['shop_id' => '500', 'secret' => 'octo-secret', 'unique_key' => 'octo-unique']);
$octoPayments = new OctoPayments($store, new OctoClient($octo, new FakeOcto));
$page = $octoPayments->start($fifth, 'https://shop.example/orders/1005', 'https://shop.example/octo');
$started = $store->all()[count($store->all()) - 1];
$notifications = new OctoNotifications($store);
$log = [...$log, ...Emulator::octo(fn (array $body) => $notifications->handle($body, $octo), 'octo-unique', $started->externalId, $started->payload['uuid'], $fifth->total, approve: true)];

foreach ($log as $step) {
    $answer = $step['response']['error'] ?? $step['response']['errorCode'] ?? null;
    $result = is_array($answer) ? 'ошибка '.$answer['code'] : (($answer !== null && $answer !== 0 && $answer !== '0') ? 'ошибка '.$answer : 'ок');
    printf("%-24s %s\n", $step['call'], $result);
}
echo "\nOcto: страница оплаты {$page}\n\n";
foreach ([$first, $second, $third, $fourth, $fifth] as $order) {
    printf("Заказ %s: %s%s\n", $order->number, $order->state->value, $order->paidVia ? ' через '.$order->paidVia : '');
}
echo 'Транзакций: ', count($store->all()), "\n";
