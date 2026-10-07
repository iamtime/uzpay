<?php

namespace Uzpay\Octo;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Uzpay\Contracts\Gateway;
use Uzpay\Http\CurlTransport;
use Uzpay\Http\Transport;
use Uzpay\Money;

/**
 * Octo API calls (secure.octo.uz): Visa, Mastercard, Uzcard and Humo cards on Octo's payment page.
 * Keys: shop_id and secret from the Octo merchant dashboard, unique_key for notifications (see Signature).
 * Every call throws OctoException when Octo answers with an error, and Http\TransportException when Octo can't be
 * reached. The payment page is in Russian (language: ru); pass 'language' in $options of prepare() to change it.
 */
final class Client
{
    public const URL = 'https://secure.octo.uz';

    private Transport $http;

    private Closure $now;

    public function __construct(private Gateway $gateway, ?Transport $http = null, ?Closure $now = null)
    {
        $this->http = $http ?? new CurlTransport;
        $this->now = $now ?? fn (): int => time();
    }

    /**
     * Start a payment: Octo answers with the page to send the payer to (octo_pay_url) and its id
     * (octo_payment_UUID). Two-stage by default: Octo holds the money and asks the notify_url to capture it.
     *
     * $options go to Octo as they are: basket (see Basket), user_data, payment_methods, language, ttl...
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function prepare(string $shopTransactionId, string $amount, string $description, string $returnUrl, ?string $notifyUrl = null, array $options = []): array
    {
        return $this->call('/prepare_payment', array_filter([
            'shop_transaction_id' => $shopTransactionId,
            'auto_capture' => false,
            'test' => $this->gateway->testMode(),
            'init_time' => (new DateTimeImmutable('@'.($this->now)()))->setTimezone(new DateTimeZone('Asia/Tashkent'))->format('Y-m-d H:i:s'),
            'total_sum' => self::sum($amount),
            'currency' => 'UZS',
            'description' => $description,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'language' => 'ru',
            'ttl' => 15,
            ...$options,
        ], fn ($v) => $v !== null));
    }

    /**
     * The payment's current state: shop_transaction_id, octo_payment_UUID, status.
     *
     * @return array<string, mixed>
     */
    public function status(string $shopTransactionId): array
    {
        return $this->call('/prepare_payment', ['shop_transaction_id' => $shopTransactionId]);
    }

    /**
     * Two-stage payment held by Octo: take the money (capture) or let it go (cancel).
     *
     * @return array<string, mixed>
     */
    public function accept(string $uuid, bool $capture, ?string $amount = null): array
    {
        return $this->call('/set_accept', array_filter([
            'octo_payment_UUID' => $uuid,
            'accept_status' => $capture ? 'capture' : 'cancel',
            'final_amount' => $amount === null ? null : self::sum($amount),
        ], fn ($v) => $v !== null));
    }

    /**
     * Return money of a succeeded payment. $refundId must be unique per refund.
     *
     * @return array<string, mixed>
     */
    public function refund(string $uuid, string $amount, string $refundId): array
    {
        return $this->call('/refund', [
            'shop_refund_id' => $refundId,
            'octo_payment_UUID' => $uuid,
            'amount' => self::sum($amount),
        ]);
    }

    /** Octo takes sums as JSON numbers in so'm. */
    public static function sum(string $amount): float
    {
        return (float) Money::soum(Money::tiyin($amount));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $path, array $body): array
    {
        $answer = $this->http->post(self::URL.$path, [
            'octo_shop_id' => (int) $this->gateway->credential('shop_id'),
            'octo_secret' => (string) $this->gateway->credential('secret'),
            ...$body,
        ]);

        if ((int) ($answer['error'] ?? -1) !== 0) {
            throw new OctoException((string) ($answer['errMessage'] ?? $answer['errorMessage'] ?? 'Octo error'), (int) ($answer['error'] ?? -1));
        }

        return is_array($answer['data'] ?? null) ? $answer['data'] : $answer;
    }
}
