<?php

namespace Uzpay\Testing;

use Uzpay\Http\Transport;

/**
 * Octo's API in memory: prepare_payment gives a payment id and a page URL, refund and set_accept succeed.
 * Pass it to Octo\Client in tests or in a sandbox, then play the notifications with Sandbox\Emulator::octo().
 * Every request is kept in $requests.
 */
final class FakeOcto implements Transport
{
    /** @var list<array{url: string, body: array<string, mixed>}> */
    public array $requests = [];

    /** @param string $pageUrl where the fake "payment page" lives; {uuid} is replaced */
    public function __construct(public string $pageUrl = 'https://pay2.octo.uz/pay/{uuid}', public ?int $error = null) {}

    public function post(string $url, array $body, array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'body' => $body];
        if ($this->error !== null) {
            return ['error' => $this->error, 'errMessage' => 'Fake error', 'data' => null];
        }
        $uuid = $this->uuid((string) ($body['shop_transaction_id'] ?? $body['octo_payment_UUID'] ?? ''));

        return ['error' => 0, 'data' => match (substr($url, strrpos($url, '/') + 1)) {
            'prepare_payment' => [
                'shop_transaction_id' => $body['shop_transaction_id'] ?? null,
                'octo_payment_UUID' => $uuid,
                'status' => 'created',
                'octo_pay_url' => str_replace('{uuid}', $uuid, $this->pageUrl),
                'refunded_sum' => 0,
                'total_sum' => $body['total_sum'] ?? 0,
            ],
            'refund' => ['octo_payment_UUID' => $body['octo_payment_UUID'] ?? null, 'refund_id' => $this->uuid('refund'.($body['shop_refund_id'] ?? '')), 'refund_time' => date('Y-m-d H:i:s'), 'status' => 'succeeded'],
            default => ['octo_payment_UUID' => $body['octo_payment_UUID'] ?? null, 'status' => ($body['accept_status'] ?? '') === 'cancel' ? 'canceled' : 'succeeded'],
        }];
    }

    /** The same id for the same transaction, as Octo gives. */
    public function uuid(string $seed): string
    {
        $hex = md5($seed);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
