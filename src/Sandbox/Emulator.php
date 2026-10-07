<?php

namespace Uzpay\Sandbox;

use Uzpay\Click\Shop;
use Uzpay\Click\Signature;
use Uzpay\Contracts\Payable;
use Uzpay\Money;
use Uzpay\Octo\Signature as OctoSignature;
use Uzpay\Octo\Status as OctoStatus;
use Uzpay\Paynet\Merchant as PaynetMerchant;
use Uzpay\Uzum\Status as UzumStatus;

/**
 * Plays the provider's side: the same calls in the same order that Payme, Click, Uzum, Paynet or Octo send when a payer pays
 * (or gives up), signed with the merchant's keys. Point it at your own callback code to try a payment
 * without the provider: a test page, a seeder, a test.
 *
 * Each call goes through $send and is logged as ['call' => name, 'request' => fields, 'response' => answer].
 */
final class Emulator
{
    /**
     * Payme: CheckPerformTransaction, CreateTransaction, then PerformTransaction (paid) or CancelTransaction.
     *
     * @param  callable(array $body, string $authorization): array  $send
     * @return list<array{call: string, request: array, response: array}>
     */
    public static function payme(callable $send, string $key, string $accountKey, Payable $payable, bool $approve, ?int $now = null): array
    {
        $params = ['amount' => Money::tiyin($payable->paymentAmount()), 'account' => [$accountKey => $payable->paymentKey()]];
        $id = bin2hex(random_bytes(12)); // Payme ids look like this: 24 hex characters
        $now ??= (int) round(microtime(true) * 1000);
        $authorization = 'Basic '.base64_encode('Paycom:'.$key);

        $log = [];
        $call = function (string $method, array $body) use ($send, $authorization, &$log): array {
            $response = $send(['jsonrpc' => '2.0', 'id' => count($log) + 1, 'method' => $method, 'params' => $body], $authorization);
            $log[] = ['call' => $method, 'request' => $body, 'response' => $response];

            return $response;
        };

        if (isset($call('CheckPerformTransaction', $params)['error'])) {
            return $log;
        }
        if (isset($call('CreateTransaction', $params + ['id' => $id, 'time' => $now])['error'])) {
            return $log;
        }
        $approve ? $call('PerformTransaction', ['id' => $id]) : $call('CancelTransaction', ['id' => $id, 'reason' => 3]);

        return $log;
    }

    /**
     * Click: Prepare, then Complete - with error -5017 («not enough money») when the payer gives up.
     *
     * @param  callable(array $params): array  $send
     * @param  string  $signTime  "Y-m-d H:i:s" in Tashkent time, as Click sends it
     * @return list<array{call: string, request: array, response: array}>
     */
    public static function click(callable $send, string $serviceId, string $secret, Payable $payable, bool $approve, string $signTime): array
    {
        $base = [
            'click_trans_id' => random_int(100000000, 999999999),
            'service_id' => $serviceId,
            'click_paydoc_id' => random_int(100000000, 999999999),
            'merchant_trans_id' => $payable->paymentKey(),
            'amount' => Money::soum(Money::tiyin($payable->paymentAmount())),
            'error' => 0,
            'error_note' => 'Success',
            'sign_time' => $signTime,
        ];

        $log = [];
        $call = function (string $name, array $p) use ($send, $secret, &$log): array {
            $p['sign_string'] = Signature::make($p, $secret);
            $response = $send($p);
            $log[] = ['call' => $name, 'request' => $p, 'response' => $response];

            return $response;
        };

        $prepared = $call('Prepare', $base + ['action' => Shop::PREPARE]);
        if (($prepared['error'] ?? -1) !== 0) {
            return $log;
        }
        $call('Complete', ['action' => Shop::COMPLETE, 'merchant_prepare_id' => $prepared['merchant_prepare_id']]
            + ($approve ? [] : ['error' => -5017, 'error_note' => 'Недостаточно средств']) + $base);

        return $log;
    }

    /**
     * Uzum: check, create, then confirm (paid) or reverse.
     *
     * @param  callable(string $action, array $body, string $authorization): array  $send
     * @return list<array{call: string, request: array, response: array}>
     */
    public static function uzum(callable $send, string $serviceId, string $login, string $password, string $accountKey, Payable $payable, bool $approve, ?int $now = null): array
    {
        $now ??= (int) round(microtime(true) * 1000);
        $authorization = 'Basic '.base64_encode($login.':'.$password);
        $base = ['serviceId' => is_numeric($serviceId) ? (int) $serviceId : $serviceId, 'timestamp' => $now];
        $params = [$accountKey => $payable->paymentKey()];
        $transId = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF));

        $log = [];
        $call = function (string $action, array $body) use ($send, $authorization, &$log): array {
            $response = $send($action, $body, $authorization);
            $log[] = ['call' => $action, 'request' => $body, 'response' => $response];

            return $response;
        };

        if (($call('check', $base + ['params' => $params])['status'] ?? null) !== UzumStatus::OK) {
            return $log;
        }
        $created = $call('create', $base + ['transId' => $transId, 'params' => $params, 'amount' => Money::tiyin($payable->paymentAmount())]);
        if (($created['status'] ?? null) !== UzumStatus::CREATED) {
            return $log;
        }
        $approve
            ? $call('confirm', $base + ['transId' => $transId, 'paymentSource' => 'CARD', 'phone' => '998901234567'])
            : $call('reverse', $base + ['transId' => $transId]);

        return $log;
    }

    /**
     * Paynet: GetInformation, PerformTransaction (Paynet has taken the money), and CancelTransaction when
     * $approve is false (the payment is undone the same day).
     *
     * @param  callable(array $body, string $authorization): array  $send
     * @return list<array{call: string, request: array, response: array}>
     */
    public static function paynet(callable $send, string $serviceId, string $login, string $password, string $accountKey, Payable $payable, bool $approve, ?int $now = null): array
    {
        $now ??= (int) round(microtime(true) * 1000);
        $authorization = 'Basic '.base64_encode($login.':'.$password);
        $fields = [$accountKey => $payable->paymentKey()];
        $service = is_numeric($serviceId) ? (int) $serviceId : $serviceId;
        $transactionId = (string) random_int(100000000, 999999999);

        $log = [];
        $call = function (string $method, array $params) use ($send, $authorization, &$log): array {
            $response = $send(['jsonrpc' => '2.0', 'id' => count($log) + 1, 'method' => $method, 'params' => $params], $authorization);
            $log[] = ['call' => $method, 'request' => $params, 'response' => $response];

            return $response;
        };

        if (isset($call('GetInformation', ['serviceId' => $service, 'fields' => $fields])['error'])) {
            return $log;
        }
        $performed = $call('PerformTransaction', [
            'serviceId' => $service,
            'transactionId' => $transactionId,
            'transactionTime' => PaynetMerchant::time($now),
            'amount' => Money::tiyin($payable->paymentAmount()),
            'fields' => $fields,
        ]);
        if (! $approve && ! isset($performed['error'])) {
            $call('CancelTransaction', ['serviceId' => $service, 'transactionId' => $transactionId]);
        }

        return $log;
    }

    /**
     * Octo: the notifications after the payer has entered the card on Octo's page - waiting_for_capture, then
     * succeeded if the answer was "capture" or canceled otherwise; a declined card gives just canceled.
     *
     * @param  callable(array $body): array  $send
     * @param  string  $shopTransactionId  the external id Octo\Payments::start() recorded
     * @return list<array{call: string, request: array, response: array}>
     */
    public static function octo(callable $send, string $uniqueKey, string $shopTransactionId, string $uuid, string $amount, bool $approve): array
    {
        $log = [];
        $call = function (string $status) use ($send, $uniqueKey, $shopTransactionId, $uuid, $amount, &$log): array {
            $body = [
                'shop_transaction_id' => $shopTransactionId,
                'octo_payment_UUID' => $uuid,
                'status' => $status,
                'signature' => OctoSignature::make($uuid, $status, $uniqueKey),
                'hash_key' => $uuid,
                'total_sum' => (float) Money::soum(Money::tiyin($amount)),
                'transfer_sum' => (float) Money::soum(Money::tiyin($amount)),
                'refunded_sum' => 0,
                'maskedPan' => '427831** **** 3011',
                'card_type' => 'internationalVisa',
                'card_vendor' => 'Visa',
                'currency' => 'UZS',
            ];
            $response = $send($body);
            $log[] = ['call' => $status, 'request' => $body, 'response' => $response];

            return $response;
        };

        if (! $approve) {
            $call(OctoStatus::CANCELED);

            return $log;
        }
        $answer = $call(OctoStatus::WAITING_FOR_CAPTURE);
        $call(($answer['accept_status'] ?? null) === 'capture' ? OctoStatus::SUCCEEDED : OctoStatus::CANCELED);

        return $log;
    }
}
