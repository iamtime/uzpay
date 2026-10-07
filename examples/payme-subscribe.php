<?php

/*
| php examples/payme-subscribe.php
|
| Payme Subscribe API: the payer saves a card once (SMS code), the shop charges it later by itself - a monthly
| subscription, «pay with a saved card» in one tap. FakePaymeSubscribe stands in for checkout.paycom.uz/api, and
| while a receipt is paid it plays the Merchant API calls Payme makes, so the order becomes paid as in real life.
|
| In a real shop:
|   - the card form (number, expiry, SMS code) calls cards.create, cards.get_verify_code and cards.verify from the
|     browser with X-Auth = Subscribe::frontAuth($gateway): the card number never touches your server;
|   - the server keeps only $card->token and $card->number (masked) and charges with Subscribe::charge();
|   - the register must also answer the Merchant API (Payme\Merchant): that is where the order becomes paid.
*/

require __DIR__.'/bootstrap.php';

use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\PayableSource;
use Uzpay\Fiscal\Receipt;
use Uzpay\PayableState;
use Uzpay\Payme\Merchant;
use Uzpay\Payme\ReceiptState;
use Uzpay\Payme\Subscribe;
use Uzpay\Payme\SubscribeException;
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\FakePaymeSubscribe;
use Uzpay\Testing\InMemoryTransactions;

/** A monthly invoice of a subscription. In an app this is your Invoice / Order model. */
final class MonthInvoice implements Payable
{
    public PayableState $state = PayableState::Open;

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
        return Receipt::make()->item('Подписка, счёт №'.$this->number, $this->total, 1, '10305001001000000', '1503426', 12)->toArray();
    }
}

$invoices = ['2001' => new MonthInvoice('2001', '99000'), '2002' => new MonthInvoice('2002', '99000')];
$source = new class($invoices) implements PayableSource
{
    public function __construct(private array $invoices) {}

    public function accountKey(): string
    {
        return 'order_id';
    }

    public function find(string $key): ?Payable
    {
        return $this->invoices[$key] ?? null;
    }
};
$keys = new class implements Gateway
{
    public function credential(string $key): ?string
    {
        return ['merchant_id' => 'demo-register', 'test_key' => 'payme-test-key'][$key] ?? null;
    }

    public function testMode(): bool
    {
        return true;
    }
};

// Payme calls the Merchant API of the register for the receipt's account while it pays it.
$merchant = new Merchant(new InMemoryTransactions, $source);
$fake = new FakePaymeSubscribe('demo-register', 'payme-test-key', function (array $receipt) use ($merchant, $keys, $source) {
    $invoice = $source->find((string) $receipt['account'][0]['value']);
    Emulator::payme(fn (array $body, string $auth) => $merchant->handle($body, $auth, $keys), 'payme-test-key', 'order_id', $invoice, approve: true);
});
$payme = new Subscribe($keys, $fake);

// 1. The payer saves the card: a token, then the SMS code (666666 for every test card).
// Only in this demo (Payme's test card) do these three calls run on the server. In production they run in the
// browser with X-Auth = Subscribe::frontAuth($keys); the server gets the token and checks it with checkCard().
$card = $payme->createCard('8600 0691 9540 6311', '03/99', save: true, customer: '998900000000');
$sms = $payme->sendVerifyCode($card->token);
echo "Код отправлен на {$sms['phone']}, живёт ".($sms['wait'] / 1000)." с\n";
try {
    $payme->verifyCard($card->token, '000000');
} catch (SubscribeException $e) {
    echo "Неверный код: {$e->getMessage()} ({$e->getCode()})\n";
}
$card = $payme->verifyCard($card->token, Subscribe::TEST_CODE);
echo "Карта {$card->number} сохранена, подтверждена: ".($card->verified ? 'да' : 'нет')."\n\n";

// 2. Every month the shop charges the saved card itself.
foreach ($invoices as $invoice) {
    $receipt = $payme->charge($invoice, $card->token, description: 'Подписка');
    printf("Счёт %s: чек %s - %s, счёт %s\n", $invoice->number, $receipt['_id'], ReceiptState::from($receipt['state'])->label(), $invoice->state->value);
}

// 3. The payer unlinks the card.
echo "\nКарта отвязана: ".($payme->removeCard($card->token) ? 'да' : 'нет')."\n";
