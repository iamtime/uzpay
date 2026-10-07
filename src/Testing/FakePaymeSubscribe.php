<?php

namespace Uzpay\Testing;

use Closure;
use Uzpay\Http\Transport;
use Uzpay\Payme\ReceiptState;
use Uzpay\Payme\Subscribe;

/**
 * Payme's Subscribe API in memory: cards with the sandbox SMS code 666666, receipts that are paid, held and canceled.
 * Pass it to Payme\Subscribe in tests or in a sandbox. $onPaid gets every paid receipt: play the Merchant API calls
 * Payme would make (Sandbox\Emulator::payme()) to mark the order paid. Every request is kept in $requests.
 *
 * Error codes and texts are the fake's own (Payme's real ones may differ; the texts are Russian in every language):
 * -32504 bad X-Auth, -31300 card unknown or not verified, -31103 wrong SMS code, -31602 receipt unknown, -31630
 * receipt in a state that does not allow it.
 */
final class FakePaymeSubscribe implements Transport
{
    /** @var list<array{url: string, body: array<string, mixed>, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, array{number: string, expire: string, recurrent: bool, verify: bool}> by token */
    public array $cards = [];

    /** @var array<string, array<string, mixed>> by receipt id */
    public array $receipts = [];

    private int $sequence = 0;

    /**
     * @param  string  $merchantId  the cashbox id the X-Auth header must carry
     * @param  string|null  $key  the key back-end calls must carry (test_key when the Gateway is in test mode);
     *                            null - not checked
     * @param  (Closure(array<string, mixed>): void)|null  $onPaid
     */
    public function __construct(public string $merchantId, public ?string $key = null, private ?Closure $onPaid = null) {}

    public function post(string $url, array $body, array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $method = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);
        $front = in_array($method, ['cards.create', 'cards.get_verify_code', 'cards.verify'], true);
        [$id, $key] = array_pad(explode(':', (string) ($headers['X-Auth'] ?? ''), 2), 2, null);
        if ($id !== $this->merchantId || (! $front && $this->key !== null && $key !== $this->key)) {
            return $this->error($body, -32504, 'Недостаточно привилегий для выполнения метода');
        }

        return match ($method) {
            'cards.create' => $this->createCard($body, $params),
            'cards.get_verify_code' => $this->withCard($body, $params, fn () => ['sent' => true, 'phone' => '99890*****31', 'wait' => 60000]),
            'cards.verify' => $this->withCard($body, $params, function (string $token) use ($body, $params) {
                if ((string) ($params['code'] ?? '') !== Subscribe::TEST_CODE) {
                    return $this->error($body, -31103, 'Неверный код');
                }
                $this->cards[$token]['verify'] = true;

                return ['card' => $this->card($token)];
            }),
            'cards.check' => $this->withCard($body, $params, fn (string $token) => ['card' => $this->card($token)]),
            'cards.remove' => $this->withCard($body, $params, function (string $token) {
                unset($this->cards[$token]);

                return ['success' => true];
            }),
            'receipts.create' => $this->ok($body, ['receipt' => $this->createReceipt($params)]),
            'receipts.pay' => $this->withReceipt($body, $params, fn (array $r) => $this->pay($body, $r, $params)),
            'receipts.send' => $this->withReceipt($body, $params, fn () => ['success' => true]),
            'receipts.cancel' => $this->withReceipt($body, $params, fn (array $r) => in_array($r['state'], [ReceiptState::Created->value, ReceiptState::Paid->value, ReceiptState::Held->value], true)
                ? ['receipt' => $this->update($r['_id'], ['state' => ReceiptState::Canceled->value, 'cancel_time' => $this->now()])]
                : $this->error($body, -31630, 'Чек нельзя отменить')),
            'receipts.confirm_hold' => $this->withReceipt($body, $params, fn (array $r) => $r['state'] === ReceiptState::Held->value
                ? ['receipt' => $this->paid($this->update($r['_id'], ['state' => ReceiptState::Paid->value]))]
                : $this->error($body, -31630, 'Чек не захолдирован')),
            'receipts.check' => $this->withReceipt($body, $params, fn (array $r) => ['state' => $r['state']]),
            'receipts.get' => $this->withReceipt($body, $params, fn (array $r) => ['receipt' => $r]),
            'receipts.get_all' => $this->ok($body, array_slice(array_values(array_filter($this->receipts,
                fn (array $r) => $r['create_time'] >= (int) ($params['from'] ?? 0) && $r['create_time'] <= (int) ($params['to'] ?? PHP_INT_MAX))),
                (int) ($params['offset'] ?? 0), (int) ($params['count'] ?? 50))),
            default => $this->error($body, -32601, 'Метод не найден'),
        };
    }

    private function createCard(array $body, array $params): array
    {
        $number = (string) ($params['card']['number'] ?? '');
        $expire = (string) ($params['card']['expire'] ?? '');
        if (! preg_match('/^\d{16}$/', $number) || ! preg_match('/^\d{4}$/', $expire)) {
            return $this->error($body, -31300, 'Неверные данные карты');
        }
        $token = base64_encode('card:'.$number.':'.(++$this->sequence));
        $this->cards[$token] = [
            'number' => substr($number, 0, 6).'******'.substr($number, -4),
            'expire' => substr($expire, 0, 2).'/'.substr($expire, 2),
            'recurrent' => (bool) ($params['save'] ?? true),
            'verify' => false,
        ];

        return $this->ok($body, ['card' => $this->card($token)]);
    }

    private function createReceipt(array $params): array
    {
        $id = substr(md5('receipt'.(++$this->sequence)), 0, 24);

        return $this->receipts[$id] = [
            '_id' => $id,
            'create_time' => $this->now(),
            'pay_time' => 0,
            'cancel_time' => 0,
            'state' => ReceiptState::Created->value,
            'amount' => (int) ($params['amount'] ?? 0),
            'currency' => 860,
            'account' => array_map(fn ($name, $value) => ['name' => $name, 'value' => (string) $value], array_keys((array) ($params['account'] ?? [])), (array) ($params['account'] ?? [])),
            'description' => (string) ($params['description'] ?? ''),
            'detail' => $params['detail'] ?? null,
            'hold' => (bool) ($params['hold'] ?? false),
            'card' => null,
        ];
    }

    private function pay(array $body, array $receipt, array $params): array
    {
        $card = $this->cards[(string) ($params['token'] ?? '')] ?? null;
        if (! $card || ! $card['verify']) {
            return $this->error($body, -31300, 'Карта не найдена или не подтверждена');
        }
        if ($receipt['state'] !== ReceiptState::Created->value) {
            return $this->error($body, -31630, 'Чек уже оплачен или отменён');
        }
        $hold = $receipt['hold'] || (bool) ($params['hold'] ?? false);
        $receipt = $this->update($receipt['_id'], [
            'state' => $hold ? ReceiptState::Held->value : ReceiptState::Paid->value,
            'pay_time' => $this->now(),
            'card' => ['number' => $card['number'], 'expire' => str_replace('/', '', $card['expire'])],
            'payer' => $params['payer'] ?? null,
        ]);
        // Single-use tokens pay once.
        if (! $card['recurrent']) {
            unset($this->cards[(string) $params['token']]);
        }

        return ['receipt' => $hold ? $receipt : $this->paid($receipt)];
    }

    private function paid(array $receipt): array
    {
        if ($this->onPaid) {
            ($this->onPaid)($receipt);
        }

        return $receipt;
    }

    private function update(string $id, array $fields): array
    {
        return $this->receipts[$id] = $fields + $this->receipts[$id];
    }

    private function withCard(array $body, array $params, Closure $then): array
    {
        $token = (string) ($params['token'] ?? '');
        if (! isset($this->cards[$token])) {
            return $this->error($body, -31300, 'Карта не найдена');
        }
        $result = $then($token);

        return isset($result['error']) ? $result : $this->ok($body, $result);
    }

    private function withReceipt(array $body, array $params, Closure $then): array
    {
        $receipt = $this->receipts[(string) ($params['id'] ?? '')] ?? null;
        if (! $receipt) {
            return $this->error($body, -31602, 'Чек не найден');
        }
        $result = $then($receipt);

        return isset($result['error']) ? $result : $this->ok($body, $result);
    }

    private function card(string $token): array
    {
        return ['token' => $token] + $this->cards[$token];
    }

    private function ok(array $body, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $body['id'] ?? null, 'result' => $result];
    }

    private function error(array $body, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $body['id'] ?? null, 'error' => ['code' => $code, 'message' => ['ru' => $message, 'uz' => $message, 'en' => $message]]];
    }

    private function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
