<?php

use Uzpay\Http\Transport;
use Uzpay\Http\TransportException;
use Uzpay\InvalidAmount;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\Payme\Card;
use Uzpay\Payme\Merchant;
use Uzpay\Payme\ReceiptState;
use Uzpay\Payme\Subscribe;
use Uzpay\Payme\SubscribeException;
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\FakePaymeSubscribe;
use Uzpay\Testing\InMemoryTransactions;
use Uzpay\UzpayException;

/*
| Payme Subscribe API: a card saved as a token (SMS code), receipts paid with it - the order becomes paid through
| the Merchant API Payme calls meanwhile; hold and confirm, cancel, errors. Helpers are in UzpayTest.php.
*/

function subscribeSetup(bool $test = true): array
{
    $order = uzpayOrder('15', '125000.50');
    $gateway = uzpayGateway(['merchant_id' => '5e730e8e0b852a417aa49ceb', 'key' => 'live-key', 'test_key' => 'test-key'], $test);
    $store = new InMemoryTransactions;
    $merchant = new Merchant($store, uzpaySource($order));
    // While a receipt is paid, Payme runs the Merchant API for its account: here the emulator does.
    $fake = new FakePaymeSubscribe('5e730e8e0b852a417aa49ceb', $test ? 'test-key' : 'live-key', function (array $receipt) use ($merchant, $gateway, $order, $test) {
        Emulator::payme(fn (array $body, string $auth) => $merchant->handle($body, $auth, $gateway), $test ? 'test-key' : 'live-key', 'order_id', $order, true);
    });

    return [$order, $gateway, $fake, new Subscribe($gateway, $fake), $store];
}

it('saves a card with the SMS code and pays an order with it; the order becomes paid through the Merchant API', function () {
    [$order, , $fake, $payme, $store] = subscribeSetup();

    $card = $payme->createCard('8600 0691 9540 6311', '03/99');
    expect($card)->toBeInstanceOf(Card::class)
        ->and($card->number)->toBe('860006******6311')
        ->and($card->recurrent)->toBeTrue()
        ->and($card->verified)->toBeFalse();
    expect($payme->sendVerifyCode($card->token))->toBe(['sent' => true, 'phone' => '99890*****31', 'wait' => 60000]);
    expect(fn () => $payme->verifyCard($card->token, '123456'))->toThrow(SubscribeException::class, 'Неверный код');
    expect($payme->verifyCard($card->token, Subscribe::TEST_CODE)->verified)->toBeTrue();

    $receipt = $payme->charge($order, $card->token, payer: ['phone' => '998900000000']);

    expect(ReceiptState::from($receipt['state']))->toBe(ReceiptState::Paid)
        ->and($receipt['amount'])->toBe(12500050)
        ->and($payme->checkReceipt($receipt['_id']))->toBe(ReceiptState::Paid)
        ->and($order->paymentState())->toBe(PayableState::Paid)
        ->and($order->paidVia)->toBe(['payme'])
        ->and($store->all())->toHaveCount(1);

    // The card form signs with the register id only, the server with "id:key"; test mode goes to the test host.
    expect($fake->requests[0]['url'])->toBe(Subscribe::TEST)
        ->and($fake->requests[0]['headers']['X-Auth'])->toBe('5e730e8e0b852a417aa49ceb')
        ->and(subscribeCalls($fake, 'receipts.create')[0]['headers']['X-Auth'])->toBe('5e730e8e0b852a417aa49ceb:test-key')
        ->and(Subscribe::frontAuth(uzpayGateway(['merchant_id' => 'm1', 'key' => 'k'])))->toBe('m1');
});

it('sends the order\'s fiscal receipt and account with the receipt', function () {
    [$order, , $fake, $payme] = subscribeSetup();
    $detail = $order->fiscalDetail();

    $receipt = $payme->receiptFor($order, description: 'Заказ 15');

    expect($receipt['state'])->toBe(ReceiptState::Created->value)
        ->and($receipt['account'])->toBe([['name' => 'order_id', 'value' => '15']]);
    $params = subscribeCalls($fake, 'receipts.create')[0]['body']['params'];
    expect($params)->toMatchArray(['amount' => 12500050, 'account' => ['order_id' => '15'], 'description' => 'Заказ 15', 'detail' => $detail])
        ->and($params)->not->toHaveKey('hold');
});

it('holds the money, then takes it or lets it go', function () {
    [, , , $payme] = subscribeSetup();
    $card = $payme->createCard('8600069195406311', '0399');
    $payme->verifyCard($card->token, Subscribe::TEST_CODE);

    $held = $payme->payReceipt($payme->createReceipt('50000', ['order_id' => '15'], hold: true)['_id'], $card->token);
    expect(ReceiptState::from($held['state']))->toBe(ReceiptState::Held)
        ->and(ReceiptState::Held->isFinal())->toBeFalse();
    expect($payme->confirmHold($held['_id'])['state'])->toBe(ReceiptState::Paid->value);

    $other = $payme->payReceipt($payme->createReceipt('50000', ['order_id' => '15'], hold: true)['_id'], $card->token);
    expect($payme->cancelReceipt($other['_id'])['state'])->toBe(ReceiptState::Canceled->value)
        ->and(ReceiptState::Canceled->isFinal())->toBeTrue();
});

it('cancels the receipt of a failed charge and carries Payme\'s error', function () {
    [$order, , $fake, $payme] = subscribeSetup();
    $card = $payme->createCard('8600069195406311', '0399'); // not verified: Payme refuses to charge it

    try {
        $payme->charge($order, $card->token);
        $this->fail('The charge must fail');
    } catch (SubscribeException $e) {
        expect($e->getCode())->toBe(-31300)
            ->and($e->messages)->toHaveKeys(['ru', 'uz', 'en']);
    }

    $receipt = array_values($fake->receipts)[0];
    expect($receipt['state'])->toBe(ReceiptState::Canceled->value)
        ->and($order->paymentState())->toBe(PayableState::Open);
});

it('pays once with a single-use token, checks and removes saved cards, lists receipts', function () {
    [, , , $payme] = subscribeSetup();
    $once = $payme->createCard('8600069195406311', '0399', save: false, customer: '998900000000');
    $payme->verifyCard($once->token, Subscribe::TEST_CODE);
    $payme->payReceipt($payme->createReceipt('1000', ['order_id' => '15'])['_id'], $once->token);
    expect(fn () => $payme->checkCard($once->token))->toThrow(SubscribeException::class);

    $saved = $payme->createCard('8600069195406311', '0399');
    expect($payme->checkCard($saved->token)->token)->toBe($saved->token)
        ->and($payme->removeCard($saved->token))->toBeTrue()
        ->and(fn () => $payme->checkCard($saved->token))->toThrow(SubscribeException::class, 'Карта не найдена');

    $payme->sendReceipt($payme->createReceipt('2000', ['order_id' => '15'])['_id'], '+998 90 000-00-00');
    expect($payme->receipts(0, PHP_INT_MAX))->toHaveCount(2)
        ->and($payme->receipts(0, PHP_INT_MAX, count: 1, offset: 1))->toHaveCount(1);
});

it('signs the live register with its key and refuses a wrong one', function () {
    [, $gateway] = subscribeSetup(test: false);
    $fake = new FakePaymeSubscribe('5e730e8e0b852a417aa49ceb', 'live-key');
    $payme = new Subscribe($gateway, $fake);

    $payme->createReceipt('1000', ['order_id' => '15']);
    expect($fake->requests[0]['url'])->toBe(Subscribe::LIVE)
        ->and($fake->requests[0]['headers']['X-Auth'])->toBe('5e730e8e0b852a417aa49ceb:live-key');

    $wrong = new Subscribe(uzpayGateway(['merchant_id' => '5e730e8e0b852a417aa49ceb', 'key' => 'nope']), $fake);
    expect(fn () => $wrong->createReceipt('1000', ['order_id' => '15']))->toThrow(SubscribeException::class);
});

/** @return list<array{url: string, body: array<string, mixed>, headers: array<string, string>}> */
function subscribeCalls(FakePaymeSubscribe $fake, string $method): array
{
    return array_values(array_filter($fake->requests, fn ($r) => ($r['body']['method'] ?? null) === $method));
}

it('throws package errors under one type and refuses to guess an unknown receipt state', function () {
    $gateway = uzpayGateway(['merchant_id' => 'm1', 'key' => 'k']);
    $odd = new class implements Transport
    {
        public function post(string $url, array $body, array $headers = []): array
        {
            return ['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['state' => 99]];
        }
    };

    expect(fn () => (new Subscribe($gateway, $odd))->checkReceipt('r1'))->toThrow(SubscribeException::class, 'Unknown receipt state')
        ->and(fn () => Money::tiyin('12,5'))->toThrow(InvalidAmount::class)
        ->and(new InvalidAmount('x'))->toBeInstanceOf(UzpayException::class)
        ->and(new SubscribeException('x', 1))->toBeInstanceOf(UzpayException::class)
        ->and(new TransportException('x'))->toBeInstanceOf(UzpayException::class)
        ->and(ReceiptState::Paid->label('en'))->toBe('Paid')
        ->and(ReceiptState::Canceled->label())->toBe('Чек отменён');
});
