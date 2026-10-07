<?php

use Uzpay\Fiscal\Receipt;
use Uzpay\Octo\Basket;
use Uzpay\Octo\Client as OctoClient;
use Uzpay\Octo\Notifications as OctoNotifications;
use Uzpay\Octo\OctoException;
use Uzpay\Octo\Payments as OctoPayments;
use Uzpay\Octo\Signature as OctoSignature;
use Uzpay\PayableState;
use Uzpay\Paynet\Checkout as PaynetCheckout;
use Uzpay\Paynet\Errors as PaynetErrors;
use Uzpay\Paynet\Merchant as PaynetMerchant;
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\FakeOcto;
use Uzpay\Testing\InMemoryTransactions;
use Uzpay\TransactionState;
use Uzpay\Uzum\Checkout as UzumCheckout;
use Uzpay\Uzum\Errors as UzumErrors;
use Uzpay\Uzum\Merchant as UzumMerchant;
use Uzpay\Uzum\Status as UzumStatus;

/*
| uzpay: Uzum, Paynet and Octo. Helpers uzpayOrder(), uzpaySource(), uzpayGateway() are in UzpayTest.php.
*/

it('pays an order through Uzum check, create and confirm, then reverses it', function () {
    $order = uzpayOrder('15', '125000.50');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['service_id' => '498624684', 'login' => 'shop', 'password' => 'pass']);
    $now = 1_700_000_000_000;
    $merchant = new UzumMerchant($store, uzpaySource($order), now: function () use (&$now) {
        return $now;
    });
    $auth = 'Basic '.base64_encode('shop:pass');
    $call = fn (string $action, array $body, ?string $a = null) => $merchant->handle($action, ['serviceId' => 498624684, 'timestamp' => $now] + $body, $a ?? $auth, $gateway);
    $params = ['params' => ['order_id' => '15']];

    expect($call('check', $params, 'Basic '.base64_encode('shop:wrong')))->toMatchArray(['status' => UzumStatus::FAILED, 'errorCode' => (string) UzumErrors::ACCESS_DENIED])
        ->and(UzumMerchant::httpStatus($call('check', $params, 'nope')))->toBe(400)
        ->and($merchant->handle('check', ['serviceId' => 1] + $params, $auth, $gateway)['errorCode'])->toBe((string) UzumErrors::INVALID_SERVICE)
        ->and($call('pay', $params)['errorCode'])->toBe((string) UzumErrors::INVALID_OPERATION)
        ->and($call('check', ['params' => ['order_id' => '99']])['errorCode'])->toBe((string) UzumErrors::ACCOUNT_NOT_FOUND)
        ->and($call('check', $params))->toMatchArray(['status' => UzumStatus::OK, 'data' => ['account' => ['value' => '15'], 'amount' => ['value' => 12500050]]]);

    expect($call('create', $params + ['transId' => 't1', 'amount' => 100])['errorCode'])->toBe((string) UzumErrors::INVALID_AMOUNT);
    $created = $call('create', $params + ['transId' => 't1', 'amount' => 12500050]);
    expect($created)->toMatchArray(['transId' => 't1', 'status' => UzumStatus::CREATED, 'transTime' => $now, 'amount' => 12500050])
        ->and($call('create', $params + ['transId' => 't1', 'amount' => 12500050])['status'])->toBe(UzumStatus::CREATED)
        ->and($store->all())->toHaveCount(1);

    $now += 1000;
    expect($call('confirm', ['transId' => 'nope'])['errorCode'])->toBe((string) UzumErrors::TRANSACTION_NOT_FOUND)
        ->and($call('confirm', ['transId' => 't1', 'paymentSource' => 'CARD']))->toMatchArray(['status' => UzumStatus::CONFIRMED, 'confirmTime' => $now])
        ->and($order->paidVia)->toBe(['uzum'])
        ->and($call('confirm', ['transId' => 't1'])['status'])->toBe(UzumStatus::CONFIRMED)
        ->and($order->paidVia)->toBe(['uzum'])
        ->and($call('check', $params)['errorCode'])->toBe((string) UzumErrors::ALREADY_PAID)
        ->and($call('status', ['transId' => 't1']))->toMatchArray(['status' => UzumStatus::CONFIRMED, 'transTime' => $now - 1000, 'confirmTime' => $now]);

    $order->refundable = false;
    expect($call('reverse', ['transId' => 't1'])['errorCode'])->toBe((string) UzumErrors::CANNOT_REVERSE);
    $order->refundable = true;
    expect($call('reverse', ['transId' => 't1']))->toMatchArray(['status' => UzumStatus::REVERSED, 'reverseTime' => $now])
        ->and($order->paymentState())->toBe(PayableState::Closed)
        ->and($store->all()[0]->state)->toBe(TransactionState::CANCELED_AFTER_PERFORM)
        ->and($call('reverse', ['transId' => 't1'])['errorCode'])->toBe((string) UzumErrors::ALREADY_REVERSED)
        ->and($call('status', ['transId' => 't1'])['status'])->toBe(UzumStatus::REVERSED);
});

it('does not confirm an Uzum payment for an order changed or paid another way', function () {
    $order = uzpayOrder('15', '50000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['service_id' => '1', 'login' => 'l', 'password' => 'p']);
    $merchant = new UzumMerchant($store, uzpaySource($order));
    $call = fn (string $action, array $body) => $merchant->handle($action, ['serviceId' => 1] + $body, 'Basic '.base64_encode('l:p'), $gateway);

    $call('create', ['transId' => 'a', 'amount' => 5000000, 'params' => ['order_id' => '15']]);
    $order->amount = '60000';
    expect($call('confirm', ['transId' => 'a'])['errorCode'])->toBe((string) UzumErrors::PAYMENT_CANCELED)
        ->and($store->all()[0]->state)->toBe(TransactionState::CANCELED)
        ->and($order->paidVia)->toBe([]);

    $order->amount = '50000';
    $log = Emulator::uzum(fn (string $action, array $body, string $auth) => $merchant->handle($action, $body, $auth, $gateway), '1', 'l', 'p', 'order_id', $order, approve: false);
    expect(array_column($log, 'call'))->toBe(['check', 'create', 'reverse'])
        ->and($log[2]['response']['status'])->toBe(UzumStatus::REVERSED)
        ->and($order->paymentState())->toBe(PayableState::Open);

    $log = Emulator::uzum(fn (string $action, array $body, string $auth) => $merchant->handle($action, $body, $auth, $gateway), '1', 'l', 'p', 'order_id', $order, approve: true);
    expect($log[2]['response']['status'])->toBe(UzumStatus::CONFIRMED)->and($order->paidVia)->toBe(['uzum']);
});

it('pays an order through Paynet in one call, cancels it and gives a statement', function () {
    $order = uzpayOrder('15', '125000.50');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['service_id' => '77', 'login' => 'paynet', 'password' => 'secret']);
    $now = 1_790_000_000_000;
    $passwords = [];
    $merchant = new PaynetMerchant($store, uzpaySource($order), changePassword: function (string $password) use (&$passwords) {
        $passwords[] = $password;
    }, now: function () use (&$now) {
        return $now;
    });
    $auth = 'Basic '.base64_encode('paynet:secret');
    $call = fn (string $method, array $params, ?string $a = null) => $merchant->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => $method, 'params' => ['serviceId' => 77] + $params], $a ?? $auth, $gateway);
    $fields = ['fields' => ['order_id' => '15']];

    expect($call('GetInformation', $fields, 'Basic '.base64_encode('paynet:x'))['error']['code'])->toBe(PaynetErrors::INVALID_LOGIN)
        ->and($merchant->handle(['id' => 1, 'method' => 'GetInformation', 'params' => ['serviceId' => 5] + $fields], $auth, $gateway)['error']['code'])->toBe(PaynetErrors::SERVICE_NOT_FOUND)
        ->and($call('Nope', [])['error']['code'])->toBe(PaynetErrors::METHOD_NOT_FOUND)
        ->and($call('GetInformation', ['fields' => ['order_id' => '1']])['error']['code'])->toBe(PaynetErrors::CLIENT_NOT_FOUND)
        ->and($call('GetInformation', $fields)['result'])->toMatchArray(['status' => '0', 'timestamp' => PaynetMerchant::time($now), 'fields' => ['order_id' => '15', 'amount' => 12500050]]);

    $perform = fn (string $id, int $amount = 12500050) => $call('PerformTransaction', $fields + ['transactionId' => $id, 'transactionTime' => PaynetMerchant::time($now), 'amount' => $amount]);
    expect($perform('p1', 100)['error']['code'])->toBe(PaynetErrors::INVALID_AMOUNT)
        ->and($perform('p1')['result'])->toMatchArray(['providerTrnId' => 1, 'fields' => ['order_id' => '15']])
        ->and($order->paidVia)->toBe(['paynet'])
        ->and($perform('p1')['error']['code'])->toBe(PaynetErrors::TRANSACTION_EXISTS)
        ->and($perform('p2')['error']['code'])->toBe(PaynetErrors::CLIENT_NOT_FOUND)
        ->and($call('CheckTransaction', ['transactionId' => 'p1'])['result'])->toMatchArray(['transactionState' => 1, 'providerTrnId' => 1])
        ->and($call('CheckTransaction', ['transactionId' => 'zz'])['result']['transactionState'])->toBe(3);

    $statement = $call('GetStatement', ['dateFrom' => PaynetMerchant::time($now - 60_000), 'dateTo' => PaynetMerchant::time($now)])['result']['statements'];
    expect($statement)->toBe([['amount' => 12500050, 'providerTrnId' => 1, 'transactionId' => 'p1', 'timestamp' => PaynetMerchant::time($now)]])
        ->and($call('GetStatement', ['dateFrom' => '04.10.2026', 'dateTo' => 'x'])['error']['code'])->toBe(PaynetErrors::INVALID_DATE);

    $order->refundable = false;
    expect($call('CancelTransaction', ['transactionId' => 'p1'])['error']['code'])->toBe(PaynetErrors::CANNOT_CANCEL);
    $order->refundable = true;
    expect($call('CancelTransaction', ['transactionId' => 'p1'])['result']['transactionState'])->toBe(2)
        ->and($order->paymentState())->toBe(PayableState::Closed)
        ->and($call('CancelTransaction', ['transactionId' => 'p1'])['error']['code'])->toBe(PaynetErrors::TRANSACTION_CANCELED)
        ->and($call('CheckTransaction', ['transactionId' => 'p1'])['result']['transactionState'])->toBe(2)
        ->and($call('GetStatement', ['dateFrom' => PaynetMerchant::time($now - 60_000), 'dateTo' => PaynetMerchant::time($now)])['result']['statements'])->toBe([]);

    expect($call('ChangePassword', ['newPassword' => 'next'])['result'])->toBe('success')->and($passwords)->toBe(['next'])
        ->and((new PaynetMerchant($store, uzpaySource($order)))->handle(['id' => 1, 'method' => 'ChangePassword', 'params' => ['newPassword' => 'x']], $auth, $gateway)['error']['code'])->toBe(PaynetErrors::ACCESS_DENIED);
});

it('emulates a Paynet payment and converts Tashkent time', function () {
    $order = uzpayOrder('8', '1000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['login' => 'l', 'password' => 'p']);
    $merchant = new PaynetMerchant($store, uzpaySource($order));

    $log = Emulator::paynet(fn (array $body, string $auth) => $merchant->handle($body, $auth, $gateway), '1', 'l', 'p', 'order_id', $order, approve: false);
    expect(array_column($log, 'call'))->toBe(['GetInformation', 'PerformTransaction', 'CancelTransaction'])
        ->and($order->paymentState())->toBe(PayableState::Closed)
        ->and(PaynetMerchant::time(1_790_000_000_000))->toBe('2026-09-21 19:13:20')
        ->and(PaynetMerchant::milliseconds('2026-09-21 19:13:21'))->toBe(1_790_000_001_000)
        ->and(PaynetCheckout::url('m1', '8', 100000))->toBe('https://app.paynet.uz/?m=m1&c=8&a=100000');
});

it('starts an Octo payment, captures it on the notification and refunds it', function () {
    $order = uzpayOrder('15', '125000.50');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['shop_id' => '42', 'secret' => 's3cret', 'unique_key' => 'uniq']);
    $octo = new FakeOcto;
    $client = new OctoClient($gateway, $octo, fn () => 1_790_000_000);
    $payments = new OctoPayments($store, $client);
    $notifications = new OctoNotifications($store);

    $url = $payments->start($order, 'https://shop.test/o/15', 'https://shop.test/pay/octo', ['basket' => [['position_desc' => 'Chexol']]]);
    $transaction = $store->all()[0];
    expect($url)->toStartWith('https://pay2.octo.uz/pay/')
        ->and($octo->requests[0]['url'])->toBe('https://secure.octo.uz/prepare_payment')
        ->and($octo->requests[0]['body'])->toMatchArray([
            'octo_shop_id' => 42, 'octo_secret' => 's3cret', 'shop_transaction_id' => $transaction->externalId, 'auto_capture' => false,
            'total_sum' => 125000.5, 'currency' => 'UZS', 'description' => 'Заказ №15', 'notify_url' => 'https://shop.test/pay/octo',
            'init_time' => '2026-09-21 19:13:20', 'basket' => [['position_desc' => 'Chexol']],
        ])
        ->and($transaction->externalId)->toStartWith('15-')
        ->and($transaction->payload['uuid'])->toBe($octo->uuid($transaction->externalId));

    $send = fn (array $body) => $notifications->handle($body, $gateway);
    $forged = ['shop_transaction_id' => $transaction->externalId, 'octo_payment_UUID' => $transaction->payload['uuid'], 'status' => 'waiting_for_capture', 'signature' => 'AA', 'total_sum' => 125000.5];
    expect($send($forged))->toBe(['error' => 'signature'])
        ->and(OctoNotifications::httpStatus(['error' => 'signature']))->toBe(400)
        ->and($order->paidVia)->toBe([]);

    $log = Emulator::octo($send, 'uniq', $transaction->externalId, $transaction->payload['uuid'], '125000.50', approve: true);
    expect(array_column($log, 'call'))->toBe(['waiting_for_capture', 'succeeded'])
        ->and($log[0]['response'])->toBe(['accept_status' => 'capture', 'final_amount' => 125000.5])
        ->and($order->paidVia)->toBe(['octo'])
        ->and($store->all()[0]->state)->toBe(TransactionState::PERFORMED)
        ->and($store->all()[0]->payload)->toMatchArray(['status' => 'succeeded', 'card_vendor' => 'Visa', 'card' => '427831** **** 3011']);

    $order->refundable = false;
    expect(fn () => $payments->refund($transaction->externalId))->toThrow(OctoException::class);
    $order->refundable = true;
    expect($payments->refund($transaction->externalId)->state)->toBe(TransactionState::CANCELED_AFTER_PERFORM)
        ->and(end($octo->requests))->toMatchArray(['url' => 'https://secure.octo.uz/refund', 'body' => ['octo_shop_id' => 42, 'octo_secret' => 's3cret', 'shop_refund_id' => $transaction->externalId.'-refund', 'octo_payment_UUID' => $transaction->payload['uuid'], 'amount' => 125000.5]])
        ->and($order->paymentState())->toBe(PayableState::Closed)
        ->and(fn () => $payments->refund($transaction->externalId))->toThrow(OctoException::class);
});

it('cancels an Octo hold for an order paid another way and keeps a declined card unpaid', function () {
    $order = uzpayOrder('15', '50000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['shop_id' => '1', 'secret' => 's', 'unique_key' => 'k']);
    $payments = new OctoPayments($store, new OctoClient($gateway, new FakeOcto));
    $send = fn (array $body) => (new OctoNotifications($store))->handle($body, $gateway);

    $payments->start($order, 'https://shop.test');
    $first = $store->all()[0];
    Emulator::octo($send, 'k', $first->externalId, $first->payload['uuid'], '50000', approve: false);
    expect($store->all()[0]->state)->toBe(TransactionState::CANCELED)->and($order->paymentState())->toBe(PayableState::Open);

    $payments->start($order, 'https://shop.test'); // the declined page is closed: a new one
    $second = $store->all()[1];
    $order->markPaidVia('payme');
    $log = Emulator::octo($send, 'k', $second->externalId, $second->payload['uuid'], '50000', approve: true);
    expect($log[0]['response'])->toBe(['accept_status' => 'cancel'])
        ->and(array_column($log, 'call'))->toBe(['waiting_for_capture', 'canceled'])
        ->and($store->all()[1]->state)->toBe(TransactionState::CANCELED)
        ->and($order->paidVia)->toBe(['payme'])
        ->and(fn () => $payments->start($order, 'https://shop.test'))->toThrow(OctoException::class);

    $failing = new OctoPayments(new InMemoryTransactions, new OctoClient($gateway, new FakeOcto(error: 2)));
    expect(fn () => $failing->start(uzpayOrder(), 'https://shop.test'))->toThrow(OctoException::class, 'Fake error');
});

it('opens one Octo page per order: the same page again, a new one for a new sum or an expiring page', function () {
    $order = uzpayOrder('17', '50000');
    $store = new InMemoryTransactions;
    $octo = new FakeOcto;
    $clock = 1_790_000_000_000;
    $payments = new OctoPayments($store, new OctoClient(uzpayGateway(['shop_id' => '1', 'secret' => 's', 'unique_key' => 'k']), $octo), now: function () use (&$clock): int {
        return $clock;
    });

    $url = $payments->start($order, 'https://shop.test');
    $clock += 5 * 60_000;
    expect($payments->start($order, 'https://shop.test'))->toBe($url) // a second tab, «back» and «pay» again
        ->and($store->all())->toHaveCount(1)->and($octo->requests)->toHaveCount(1);

    $order->amount = '60000'; // the order changed: the old page has the old sum
    $changed = $payments->start($order, 'https://shop.test');
    $clock += 14 * 60_000; // 1 minute of its 15 left: too little to pay
    expect($changed)->not->toBe($url)
        ->and($payments->start($order, 'https://shop.test'))->not->toBe($changed)
        ->and($store->all())->toHaveCount(3);
});

it('refunds a second Octo payment of a paid order without unpaying the order', function () {
    $order = uzpayOrder('16', '50000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['shop_id' => '1', 'secret' => 's', 'unique_key' => 'k']);
    $clock = 1_790_000_000_000;
    $payments = new OctoPayments($store, new OctoClient($gateway, new FakeOcto), now: function () use (&$clock): int {
        return $clock;
    });
    $send = fn (array $body) => (new OctoNotifications($store))->handle($body, $gateway);

    // Two live pages only when the first was about to expire.
    $payments->start($order, 'https://shop.test');
    $clock += 14 * 60_000;
    $payments->start($order, 'https://shop.test');
    [$first, $second] = $store->all();
    Emulator::octo($send, 'k', $first->externalId, $first->payload['uuid'], '50000', approve: true);
    // The second tab was paid too, one-stage: the money is taken, the order was already paid by the first one.
    $uuid = $second->payload['uuid'];
    $send(['shop_transaction_id' => $second->externalId, 'octo_payment_UUID' => $uuid, 'status' => 'succeeded', 'total_sum' => 50000, 'signature' => OctoSignature::make($uuid, 'succeeded', 'k')]);
    expect($store->all()[1]->state)->toBe(TransactionState::PERFORMED)->and($store->all()[1]->payload['unclaimed'] ?? false)->toBeTrue();

    // A shipped order may refuse refunds, but the duplicate is not its money: it goes back and the order stays paid.
    $order->refundable = false;
    expect($payments->refund($second->externalId)->state)->toBe(TransactionState::CANCELED_AFTER_PERFORM)
        ->and($order->paymentState())->toBe(PayableState::Paid)
        ->and(fn () => $payments->refund($first->externalId))->toThrow(OctoException::class);
});

it('builds the Octo basket and signature', function () {
    $receipt = Receipt::make()->item('Чехол «Люкс» для iPhone', '15000.5', 2, '06109001001000000', '1503426', 12)
        ->item('Ўзбекча қўлланма', '1000', 1, '06109001001000001', '1503427', 0);

    expect(Basket::fromReceipt($receipt->toArray(), '123456789'))->toBe([
        ['position_desc' => 'Chexol Lyuks dlya iPhone', 'count' => 2, 'price' => 15000.5, 'spic' => '06109001001000000', 'inn' => '123456789', 'package_code' => '1503426', 'nds' => 1],
        ['position_desc' => 'Ozbekcha qollanma', 'count' => 1, 'price' => 1000.0, 'spic' => '06109001001000001', 'inn' => '123456789', 'package_code' => '1503427', 'nds' => 0],
    ])
        ->and(Basket::fromReceipt($receipt->toArray(), '12345678901234', individual: true)[0])->toHaveKey('pinfl')
        ->and(Basket::fromReceipt((clone $receipt)->discount('Скидка', '100')->toArray(), '1'))->toBeNull()
        ->and(Basket::fromReceipt(Receipt::make()->item('Без кода', '1', 1, null, null)->toArray(), '1'))->toBeNull()
        ->and(OctoSignature::make('uuid-1', 'succeeded', 'key'))->toBe(strtoupper(sha1('keyuuid-1succeeded')))
        ->and(OctoSignature::valid(['octo_payment_UUID' => 'uuid-1', 'status' => 'succeeded', 'signature' => strtolower(OctoSignature::make('uuid-1', 'succeeded', 'key'))], 'key'))->toBeTrue()
        ->and(OctoSignature::valid(['octo_payment_UUID' => 'u', 'status' => 's', 'signature' => 'x'], null))->toBeFalse()
        ->and(UzumCheckout::url('498', 'order_id', '15', 100, 'https://shop.test/o/15'))->toBe('https://www.uzumbank.uz/open-service?serviceId=498&order_id=15&amount=100&redirectUrl=https%3A%2F%2Fshop.test%2Fo%2F15');
});
