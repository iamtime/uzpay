<?php

use Uzpay\Click\Checkout as ClickCheckout;
use Uzpay\Click\Shop;
use Uzpay\Click\Signature;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\PayableSource;
use Uzpay\Fiscal\Receipt;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\Payme\Checkout as PaymeCheckout;
use Uzpay\Payme\Errors;
use Uzpay\Payme\Merchant;
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\InMemoryTransactions;
use Uzpay\TransactionState;

/*
| uzpay (packages/uzpay): Payme and Click protocols without a framework or a database - an order in memory,
| the in-memory transaction store, the provider's calls from the emulator.
*/

function uzpayOrder(string $key = '15', string $amount = '125000.50'): Payable
{
    return new class($key, $amount) implements Payable
    {
        public PayableState $state = PayableState::Open;

        public array $paidVia = [];

        public bool $refundable = true;

        public function __construct(public string $key, public string $amount) {}

        public function paymentKey(): string
        {
            return $this->key;
        }

        public function paymentAmount(): string
        {
            return $this->amount;
        }

        public function paymentState(): PayableState
        {
            return $this->state;
        }

        public function markPaidVia(string $provider): void
        {
            $this->paidVia[] = $provider;
            $this->state = PayableState::Paid;
        }

        public function canRefund(): bool
        {
            return $this->refundable;
        }

        public function markRefunded(): void
        {
            $this->state = PayableState::Closed;
        }

        public function fiscalDetail(): array
        {
            return Receipt::make()->item('Чехол', $this->amount, 1, '06109001001000000', '1503426', 12)->toArray();
        }
    };
}

function uzpaySource(Payable ...$orders): PayableSource
{
    return new class($orders) implements PayableSource
    {
        public function __construct(private array $orders) {}

        public function accountKey(): string
        {
            return 'order_id';
        }

        public function find(string $key): ?Payable
        {
            foreach ($this->orders as $order) {
                if ($order->paymentKey() === $key) {
                    return $order;
                }
            }

            return null;
        }
    };
}

function uzpayGateway(array $credentials, bool $test = false): Gateway
{
    return new class($credentials, $test) implements Gateway
    {
        public function __construct(private array $credentials, private bool $test) {}

        public function credential(string $key): ?string
        {
            return $this->credentials[$key] ?? null;
        }

        public function testMode(): bool
        {
            return $this->test;
        }
    };
}

it('converts so\'m and tiyin digit by digit', function () {
    expect(Money::tiyin('125000.50'))->toBe(12500050)
        ->and(Money::tiyin('0.1'))->toBe(10)
        ->and(Money::tiyin('19.999'))->toBe(2000)
        ->and(Money::tiyin('-3.05'))->toBe(-305)
        ->and(Money::tiyin(7))->toBe(700)
        ->and(Money::soum(12500050))->toBe('125000.50')
        ->and(Money::soum(5))->toBe('0.05')
        ->and(Money::same('100', '100.00'))->toBeTrue()
        ->and(fn () => Money::tiyin('12,5'))->toThrow(InvalidArgumentException::class);
});

it('pays an order through the whole Payme sequence and refunds it', function () {
    $order = uzpayOrder();
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['key' => 'live-key']);
    $now = 1_700_000_000_000;
    $merchant = new Merchant($store, uzpaySource($order), now: function () use (&$now) {
        return $now;
    });
    $auth = 'Basic '.base64_encode('Paycom:live-key');
    $call = fn (string $method, array $params) => $merchant->handle(['id' => 1, 'method' => $method, 'params' => $params], $auth, $gateway);
    $params = ['amount' => 12500050, 'account' => ['order_id' => '15']];

    expect($call('CheckPerformTransaction', $params)['result'])->toMatchArray(['allow' => true])
        ->and($call('CheckPerformTransaction', ['amount' => 100] + $params)['error']['code'])->toBe(Errors::WRONG_AMOUNT)
        ->and($call('CheckPerformTransaction', ['account' => ['order_id' => '99']] + $params)['error']['code'])->toBe(Errors::ORDER_NOT_FOUND);

    $created = $call('CreateTransaction', $params + ['id' => 'abc', 'time' => $now]);
    expect($created['result']['state'])->toBe(TransactionState::CREATED)
        ->and($call('CreateTransaction', $params + ['id' => 'abc', 'time' => $now])['result']['transaction'])->toBe($created['result']['transaction'])
        ->and($call('CreateTransaction', $params + ['id' => 'other', 'time' => $now])['error']['code'])->toBe(Errors::ORDER_BUSY);

    $now += 1000;
    expect($call('PerformTransaction', ['id' => 'abc'])['result'])->toMatchArray(['state' => TransactionState::PERFORMED, 'perform_time' => $now])
        ->and($order->paidVia)->toBe(['payme'])
        ->and($call('PerformTransaction', ['id' => 'abc'])['result']['state'])->toBe(TransactionState::PERFORMED)
        ->and($order->paidVia)->toBe(['payme']);

    $statement = $call('GetStatement', ['from' => $now - 5000, 'to' => $now])['result']['transactions'];
    expect($statement)->toHaveCount(1)->and($statement[0])->toMatchArray(['id' => 'abc', 'amount' => 12500050, 'account' => ['order_id' => '15']]);

    $order->refundable = false;
    expect($call('CancelTransaction', ['id' => 'abc', 'reason' => 5])['error']['code'])->toBe(Errors::CANNOT_CANCEL);
    $order->refundable = true;
    expect($call('CancelTransaction', ['id' => 'abc', 'reason' => 5])['result']['state'])->toBe(TransactionState::CANCELED_AFTER_PERFORM)
        ->and($order->paymentState())->toBe(PayableState::Closed)
        ->and($call('CheckTransaction', ['id' => 'abc'])['result']['reason'])->toBe(5);
});

it('refuses a Payme call with a wrong key and cancels an expired or changed transaction', function () {
    $order = uzpayOrder();
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['key' => 'live', 'test_key' => 'test'], test: true);
    $now = 1_700_000_000_000;
    $merchant = new Merchant($store, uzpaySource($order), now: function () use (&$now) {
        return $now;
    });
    $call = fn (string $key, string $method, array $params) => $merchant->handle(['id' => 7, 'method' => $method, 'params' => $params], 'Basic '.base64_encode('Paycom:'.$key), $gateway);
    $params = ['amount' => 12500050, 'account' => ['order_id' => '15']];

    expect($call('live', 'CheckPerformTransaction', $params)['error']['code'])->toBe(Errors::INSUFFICIENT_PRIVILEGE)
        ->and($call('test', 'NoSuchMethod', [])['error'])->toMatchArray(['code' => Errors::METHOD_NOT_FOUND, 'data' => 'NoSuchMethod']);

    $call('test', 'CreateTransaction', $params + ['id' => 'old', 'time' => $now]);
    $now += Merchant::TIMEOUT_MS + 1;
    expect($call('test', 'PerformTransaction', ['id' => 'old'])['error']['code'])->toBe(Errors::CANNOT_PERFORM)
        ->and($store->all()[0]->reason)->toBe(4);

    $call('test', 'CreateTransaction', $params + ['id' => 'new', 'time' => $now]);
    $order->amount = '130000';
    expect($call('test', 'PerformTransaction', ['id' => 'new'])['error']['code'])->toBe(Errors::CANNOT_PERFORM)
        ->and($store->all()[1]->state)->toBe(TransactionState::CANCELED)
        ->and($order->paidVia)->toBe([]);
});

it('pays an order through Click Prepare and Complete, checking the signature', function () {
    $order = uzpayOrder('42', '50000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['service_id' => '100', 'secret_key' => 's3cret']);
    $shop = new Shop($store, uzpaySource($order));
    $base = ['click_trans_id' => 555, 'service_id' => '100', 'merchant_trans_id' => '42', 'amount' => '50000.00', 'sign_time' => '2026-10-04 12:00:00', 'error' => 0];
    $signed = fn (array $p) => $p + ['sign_string' => Signature::make($p, 's3cret')];

    expect($shop->handle(['action' => 0, 'sign_string' => 'bad'] + $base, $gateway)['error'])->toBe(-1)
        ->and($shop->handle($signed(['action' => 0, 'amount' => '49000'] + $base), $gateway)['error'])->toBe(-2)
        ->and($shop->handle($signed(['action' => 0, 'merchant_trans_id' => '1'] + $base), $gateway)['error'])->toBe(-5);

    $prepared = $shop->handle($signed(['action' => 0] + $base), $gateway);
    expect($prepared['error'])->toBe(0)
        ->and($shop->handle($signed(['action' => 0] + $base), $gateway)['merchant_prepare_id'])->toBe($prepared['merchant_prepare_id']);

    $complete = $signed(['action' => 1, 'merchant_prepare_id' => $prepared['merchant_prepare_id']] + $base);
    expect($shop->handle($complete, $gateway))->toMatchArray(['error' => 0, 'merchant_confirm_id' => $prepared['merchant_prepare_id']])
        ->and($order->paidVia)->toBe(['click'])
        ->and($shop->handle($complete, $gateway)['error'])->toBe(-4)
        ->and($order->paidVia)->toBe(['click']);
});

it('cancels a Click payment the payer could not finish', function () {
    $order = uzpayOrder('42', '50000');
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['service_id' => '100', 'secret_key' => 's3cret']);
    $shop = new Shop($store, uzpaySource($order));

    $log = Emulator::click(fn (array $p) => $shop->handle($p, $gateway), '100', 's3cret', $order, approve: false, signTime: '2026-10-04 12:00:00');

    expect(array_column($log, 'call'))->toBe(['Prepare', 'Complete'])
        ->and($log[1]['response']['error'])->toBe(-9)
        ->and($store->all()[0]->state)->toBe(TransactionState::CANCELED)
        ->and($order->paymentState())->toBe(PayableState::Open);
});

it('emulates a Payme payment against the merchant code', function () {
    $order = uzpayOrder();
    $store = new InMemoryTransactions;
    $gateway = uzpayGateway(['key' => 'k']);
    $merchant = new Merchant($store, uzpaySource($order));

    $log = Emulator::payme(fn (array $body, string $auth) => $merchant->handle($body, $auth, $gateway), 'k', 'order_id', $order, approve: true);

    expect(array_column($log, 'call'))->toBe(['CheckPerformTransaction', 'CreateTransaction', 'PerformTransaction'])
        ->and($log[0]['response']['result']['detail']['items'][0])->toMatchArray(['price' => 12500050, 'code' => '06109001001000000'])
        ->and($order->paidVia)->toBe(['payme']);
});

it('builds checkout links', function () {
    $payme = PaymeCheckout::url('m1', 'order_id', '15', 12500050, 'https://shop.test/o/15');
    $click = ClickCheckout::url('100', '200', '50000', '42', 'https://shop.test/o/42');

    expect($payme)->toStartWith(PaymeCheckout::LIVE.'/')
        ->and(base64_decode(substr($payme, strlen(PaymeCheckout::LIVE) + 1)))->toBe('m=m1;ac.order_id=15;a=12500050;c=https://shop.test/o/15')
        ->and(PaymeCheckout::url('m1', 'order_id', '15', 1, 'x', test: true))->toStartWith(PaymeCheckout::TEST)
        ->and($click)->toContain('amount=50000.00')->toContain('transaction_param=42');
});

it('builds a fiscal receipt and tells what the tax office will not accept', function () {
    $receipt = Receipt::make()
        ->item(str_repeat('Я', 200), '15000.5', 2, '06109001001000000', '1503426', 12)
        ->item('Без кода', '1000', 1, null, '', 15)
        ->shipping('Доставка', '0')
        ->discount('Скидка', '500');

    expect($receipt->toArray())->toMatchArray(['receipt_type' => 0, 'discount' => ['title' => 'Скидка', 'price' => 50000]])
        ->and($receipt->toArray())->not->toHaveKey('shipping')
        ->and(mb_strlen($receipt->toArray()['items'][0]['title']))->toBe(Receipt::TITLE_MAX)
        ->and($receipt->toArray()['items'][0]['price'])->toBe(1500050)
        ->and($receipt->problems())->toBe(['items.1.code', 'items.1.package_code', 'items.1.vat_percent'])
        ->and(Receipt::make()->problems())->toBe(['items']);
});
