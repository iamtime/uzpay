<?php

namespace Uzpay\Payme;

use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Http\CurlTransport;
use Uzpay\Http\Transport;
use Uzpay\Money;

/**
 * Payme Subscribe API (checkout.paycom.uz/api): saved cards and receipts the merchant charges itself - repeat
 * payments, subscriptions, "pay with a saved card" in one tap.
 *
 * Keys are those of the Merchant API: merchant_id (the cashbox id), key, test_key. Payme enables the Subscribe API
 * for the cashbox on request. The same cashbox must answer the Merchant API (Payme\Merchant): while a receipt is
 * paid, Payme calls CheckPerformTransaction, CreateTransaction and PerformTransaction for the receipt's account -
 * that is where the order becomes paid (Payable::markPaidVia), never from the answer of payReceipt() or charge().
 *
 * Cards: cards.create → cards.get_verify_code → the owner types the SMS code → cards.verify. These three are
 * "front-end" methods (X-Auth with the cashbox id only): Payme expects them from the payer's browser, so the card
 * number never reaches the merchant's server, which receives only the token and checks it with checkCard() before
 * keeping it. frontAuth() gives the header for that page. Calling them from a server means handling card numbers
 * (PCI DSS).
 *
 * Every call throws SubscribeException when Payme answers with an error, and Http\TransportException when Payme
 * can't be reached - then the outcome is unknown: ask checkReceipt() before trying again.
 */
final class Subscribe
{
    public const LIVE = 'https://checkout.paycom.uz/api';

    public const TEST = 'https://checkout.test.paycom.uz/api';

    /** The SMS code of every test card in Payme's sandbox. */
    public const TEST_CODE = '666666';

    private Transport $http;

    private int $requestId = 0;

    public function __construct(private Gateway $gateway, ?Transport $http = null)
    {
        $this->http = $http ?? new CurlTransport;
    }

    /** X-Auth for the card form in the browser: the cashbox id, without the key. */
    public static function frontAuth(Gateway $gateway): string
    {
        return (string) $gateway->credential('merchant_id');
    }

    /** checkout.test.paycom.uz in test mode (with test_key), checkout.paycom.uz otherwise. */
    public function url(): string
    {
        return $this->gateway->testMode() ? self::TEST : self::LIVE;
    }

    // ---- Cards ----------------------------------------------------------------------------------------------

    /**
     * A token for a card. $save: true - the token pays again and again (subscriptions), false - once.
     * $expire is MMYY ("0399"; "03/99" is accepted too). $customer - any id of the payer (phone, uid, email) Payme
     * keeps with the card.
     */
    public function createCard(string $number, string $expire, bool $save = true, ?string $customer = null): Card
    {
        return Card::fromArray($this->call('cards.create', array_filter([
            'card' => ['number' => preg_replace('/\D+/', '', $number), 'expire' => preg_replace('/\D+/', '', $expire)],
            'save' => $save,
            'customer' => $customer,
        ], fn ($v) => $v !== null), front: true)['card'] ?? []);
    }

    /**
     * Payme sends the SMS code to the card owner's phone.
     *
     * @return array{sent: bool, phone: string, wait: int} phone - masked, wait - how long the code lives, ms
     */
    public function sendVerifyCode(string $token): array
    {
        $answer = $this->call('cards.get_verify_code', ['token' => $token], front: true);

        return ['sent' => (bool) ($answer['sent'] ?? false), 'phone' => (string) ($answer['phone'] ?? ''), 'wait' => (int) ($answer['wait'] ?? 0)];
    }

    /** The owner's SMS code: the card becomes verified and may pay. */
    public function verifyCard(string $token, string $code): Card
    {
        return Card::fromArray($this->call('cards.verify', ['token' => $token, 'code' => trim($code)], front: true)['card'] ?? []);
    }

    /**
     * The card behind a token: check verified and recurrent here before keeping a token the browser sent.
     * Throws SubscribeException when the token was removed or the card expired or was blocked.
     */
    public function checkCard(string $token): Card
    {
        return Card::fromArray($this->call('cards.check', ['token' => $token])['card'] ?? []);
    }

    /** Unlink the card: the token stops working. */
    public function removeCard(string $token): bool
    {
        return (bool) ($this->call('cards.remove', ['token' => $token])['success'] ?? false);
    }

    // ---- Receipts -------------------------------------------------------------------------------------------

    /**
     * A receipt to pay. $amount is in so'm ("125000.50"), $account - the order's fields as the Merchant API of the
     * cashbox expects them (['order_id' => '15']), $detail - the fiscal receipt (Fiscal\Receipt::toArray()).
     *
     * $hold: the payment only holds the money (state 5); confirmHold() takes it, cancelReceipt() lets it go.
     * Payme wants the flag on both receipts.create and receipts.pay, and enables holding for the cashbox on request.
     *
     * @param  array<string, string|int>  $account
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed> the receipt: _id, state, amount (tiyin), account...
     */
    public function createReceipt(string|int|float $amount, array $account, ?string $description = null, ?array $detail = null, bool $hold = false): array
    {
        return $this->receipt($this->call('receipts.create', array_filter([
            'amount' => Money::tiyin($amount),
            'account' => $account,
            'description' => $description,
            'detail' => $detail ?: null,
            'hold' => $hold ?: null,
        ], fn ($v) => $v !== null)));
    }

    /**
     * A receipt for an order: its number goes to $accountKey, the sum and the fiscal detail come from the order.
     * $accountKey must be the field the cashbox's Merchant API looks orders up by (PayableSource::accountKey()),
     * otherwise Payme's CheckPerformTransaction will not find the order.
     *
     * @return array<string, mixed>
     */
    public function receiptFor(Payable $payable, string $accountKey = 'order_id', ?string $description = null, bool $hold = false): array
    {
        return $this->createReceipt($payable->paymentAmount(), [$accountKey => $payable->paymentKey()], $description, $payable->fiscalDetail(), $hold);
    }

    /**
     * Pay a receipt with a saved card. Payme then calls the cashbox's Merchant API for the receipt's account.
     * $payer - optional: id, phone, email, name, ip.
     *
     * @param  array<string, string>  $payer
     * @return array<string, mixed> the receipt; state 4 - paid, 5 - held
     */
    public function payReceipt(string $receiptId, string $token, array $payer = [], bool $hold = false): array
    {
        return $this->receipt($this->call('receipts.pay', array_filter([
            'id' => $receiptId,
            'token' => $token,
            'payer' => $payer ?: null,
            'hold' => $hold ?: null,
        ], fn ($v) => $v !== null)));
    }

    /**
     * Create a receipt for the order and pay it with the card in one go. When Payme refuses the payment, the
     * receipt is canceled (no unpaid receipts pile up) and the original error is rethrown. On a TransportException
     * the receipt is left as it is: the outcome is unknown, ask checkReceipt() before charging again.
     *
     * @param  array<string, string>  $payer
     * @return array<string, mixed>
     */
    public function charge(Payable $payable, string $token, string $accountKey = 'order_id', ?string $description = null, array $payer = [], bool $hold = false): array
    {
        $receipt = $this->receiptFor($payable, $accountKey, $description, $hold);
        try {
            return $this->payReceipt((string) $receipt['_id'], $token, $payer, $hold);
        } catch (SubscribeException $e) {
            try {
                $this->cancelReceipt((string) $receipt['_id']);
            } catch (SubscribeException) {
                // Already gone or being paid: Payme decides; the first error is the one the merchant needs.
            }
            throw $e;
        }
    }

    /** Payme sends the payer an SMS with a link to pay the receipt. */
    public function sendReceipt(string $receiptId, string $phone): bool
    {
        return (bool) ($this->call('receipts.send', ['id' => $receiptId, 'phone' => preg_replace('/\D+/', '', $phone)])['success'] ?? false);
    }

    /**
     * Cancel a receipt: an unpaid one is dropped, a paid one is refunded (Payme calls CancelTransaction of the
     * Merchant API), a held one lets the money go.
     *
     * @return array<string, mixed>
     */
    public function cancelReceipt(string $receiptId): array
    {
        return $this->receipt($this->call('receipts.cancel', ['id' => $receiptId]));
    }

    /**
     * Take the money of a held receipt (state 5 → 4).
     *
     * @return array<string, mixed>
     */
    public function confirmHold(string $receiptId): array
    {
        return $this->receipt($this->call('receipts.confirm_hold', ['id' => $receiptId]));
    }

    /** The receipt's state; a state the package does not know is a SubscribeException, not a guess. */
    public function checkReceipt(string $receiptId): ReceiptState
    {
        $state = $this->call('receipts.check', ['id' => $receiptId])['state'] ?? null;

        return (is_int($state) ? ReceiptState::tryFrom($state) : null)
            ?? throw new SubscribeException('Unknown receipt state: '.var_export($state, true), -1);
    }

    /** @return array<string, mixed> */
    public function getReceipt(string $receiptId): array
    {
        return $this->receipt($this->call('receipts.get', ['id' => $receiptId]));
    }

    /**
     * Receipts of the cashbox created between $from and $to (unix time in ms), up to 50 a page.
     *
     * @return list<array<string, mixed>>
     */
    public function receipts(int $from, int $to, int $count = 50, int $offset = 0): array
    {
        $answer = $this->call('receipts.get_all', ['count' => max(1, min(50, $count)), 'from' => $from, 'to' => $to, 'offset' => max(0, $offset)]);

        return array_values(array_filter(array_is_list($answer) ? $answer : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function receipt(array $result): array
    {
        return is_array($result['receipt'] ?? null) ? $result['receipt'] : $result;
    }

    /**
     * One JSON-RPC call. Back-end methods sign with "id:key" (test_key in test mode, the live key when there is
     * no test_key), front-end ones with the id only.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, bool $front = false): array
    {
        $id = (string) $this->gateway->credential('merchant_id');
        $key = $this->gateway->testMode()
            ? ($this->gateway->credential('test_key') ?? $this->gateway->credential('key'))
            : $this->gateway->credential('key');

        $answer = $this->http->post($this->url(), ['id' => ++$this->requestId, 'method' => $method, 'params' => $params], [
            'X-Auth' => $front ? $id : $id.':'.$key,
            'Cache-Control' => 'no-cache',
        ]);

        if (is_array($answer['error'] ?? null)) {
            throw SubscribeException::fromError($answer['error']);
        }

        return is_array($answer['result'] ?? null) ? $answer['result'] : [];
    }
}
