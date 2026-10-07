<?php

namespace Uzpay\Uzum;

use Closure;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\PayableSource;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\Transaction;
use Uzpay\TransactionState as State;

/**
 * Uzum Bank Merchant API: Uzum calls <your url>/check, /create, /confirm, /reverse and /status with JSON,
 * Basic auth (login and password from the Uzum cabinet), amounts in tiyin, times in milliseconds.
 * Give it the last path segment, the decoded body and the Authorization header; send back the array it
 * returns as JSON with Merchant::httpStatus() of it.
 *
 * The payable's key comes in params.<account key> (order_id), as the field is named in the Uzum cabinet.
 */
final class Merchant
{
    public const ACTIONS = ['check', 'create', 'confirm', 'reverse', 'status'];

    private Closure $now;

    public function __construct(
        private TransactionStore $store,
        private PayableSource $source,
        private string $provider = 'uzum',
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function handle(string $action, array $body, ?string $authorization, Gateway $gateway): array
    {
        if (! self::authorized($authorization, $gateway)) {
            return $this->failed($body, Errors::ACCESS_DENIED);
        }
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->failed($body, Errors::INVALID_OPERATION);
        }
        if ((string) ($body['serviceId'] ?? '') !== (string) $gateway->credential('service_id')) {
            return $this->failed($body, Errors::INVALID_SERVICE);
        }
        if ($action !== 'check' && ! is_scalar($body['transId'] ?? null)) {
            return $this->failed($body, Errors::MISSING_PARAMS);
        }

        return match ($action) {
            'check' => $this->check($body),
            'create' => $this->store->atomic(fn (): array => $this->create($body)),
            'confirm' => $this->store->atomic(fn (): array => $this->confirm($body)),
            'reverse' => $this->store->atomic(fn (): array => $this->reverse($body)),
            'status' => $this->status($body),
        };
    }

    /** Uzum reads the answer from the body; errors go with HTTP 400 as its examples show. */
    public static function httpStatus(array $answer): int
    {
        return ($answer['status'] ?? null) === Status::FAILED ? 400 : 200;
    }

    public static function authorized(?string $authorization, Gateway $gateway): bool
    {
        $login = $gateway->credential('login');
        $password = $gateway->credential('password');
        if ($login === null || $password === null || ! str_starts_with((string) $authorization, 'Basic ')) {
            return false;
        }
        [$user, $secret] = array_pad(explode(':', (string) base64_decode(substr((string) $authorization, 6)), 2), 2, '');

        return hash_equals($login, $user) && hash_equals($password, $secret);
    }

    private function check(array $body): array
    {
        $payable = $this->payableFor($body);
        if (is_int($payable)) {
            return $this->failed($body, $payable);
        }

        return [
            'serviceId' => $body['serviceId'],
            'timestamp' => $this->now(),
            'status' => Status::OK,
            'data' => $this->data($payable),
        ];
    }

    private function create(array $body): array
    {
        $existing = $this->store->find($this->provider, (string) $body['transId']);
        if ($existing) {
            // Uzum repeats create when our answer was lost: the same transaction, not a second one.
            return $existing->state === State::CREATED
                ? $this->answer($body, $existing, Status::CREATED, ['transTime' => $existing->createTime])
                : $this->failed($body, Errors::TRANSACTION_EXISTS);
        }

        $payable = $this->payableFor($body, lock: true);
        if (is_int($payable)) {
            return $this->failed($body, $payable);
        }
        if (! is_numeric($body['amount'] ?? null) || Money::tiyin($payable->paymentAmount()) !== (int) $body['amount']) {
            return $this->failed($body, Errors::INVALID_AMOUNT);
        }

        $transaction = $this->store->create($this->provider, $payable, [
            'external_id' => (string) $body['transId'],
            'amount' => $payable->paymentAmount(),
            'state' => State::CREATED,
            'create_time' => $this->now(),
            'payload' => ['params' => $body['params'] ?? []],
        ]);

        return $this->answer($body, $transaction, Status::CREATED, ['transTime' => $transaction->createTime], $payable);
    }

    private function confirm(array $body): array
    {
        $transaction = $this->store->find($this->provider, (string) $body['transId']);
        if (! $transaction) {
            return $this->failed($body, Errors::TRANSACTION_NOT_FOUND);
        }
        if ($transaction->state === State::PERFORMED) {
            return $this->answer($body, $transaction, Status::CONFIRMED, ['confirmTime' => $transaction->performTime]);
        }
        if ($transaction->state !== State::CREATED) {
            return $this->failed($body, Errors::TRANSACTION_CANCELED);
        }

        // The order may have been canceled, changed or paid another way since create: then it is not paid.
        $payable = $this->store->payable($transaction, lock: true);
        if (! $payable || $this->store->paidElsewhere($transaction) || $payable->paymentState() !== PayableState::Open
            || ! Money::same($payable->paymentAmount(), $transaction->amount)) {
            $this->store->update($transaction, ['state' => State::CANCELED, 'reason' => 5, 'cancel_time' => $this->now()]);

            return $this->failed($body, $payable?->paymentState() === PayableState::Paid ? Errors::ALREADY_PAID : Errors::PAYMENT_CANCELED);
        }

        $transaction = $this->store->update($transaction, [
            'state' => State::PERFORMED,
            'perform_time' => $this->now(),
            'payload' => $transaction->payload + array_filter([
                'payment_source' => $body['paymentSource'] ?? null,
                'phone' => $body['phone'] ?? null,
                'card_type' => $body['cardType'] ?? null,
                'reference' => $body['processingReferenceNumber'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
        $payable->markPaidVia($this->provider);

        return $this->answer($body, $transaction, Status::CONFIRMED, ['confirmTime' => $transaction->performTime], $payable);
    }

    private function reverse(array $body): array
    {
        $transaction = $this->store->find($this->provider, (string) $body['transId']);
        if (! $transaction) {
            return $this->failed($body, Errors::TRANSACTION_NOT_FOUND);
        }
        if (in_array($transaction->state, [State::CANCELED, State::CANCELED_AFTER_PERFORM], true)) {
            return $this->failed($body, Errors::ALREADY_REVERSED);
        }

        $payable = null;
        if ($transaction->state === State::PERFORMED) {
            $payable = $this->store->payable($transaction, lock: true);
            if (! $payable?->canRefund()) {
                return $this->failed($body, Errors::CANNOT_REVERSE);
            }
        }
        $transaction = $this->store->update($transaction, [
            'state' => $payable ? State::CANCELED_AFTER_PERFORM : State::CANCELED,
            'cancel_time' => $this->now(),
        ]);
        $payable?->markRefunded();

        return $this->answer($body, $transaction, Status::REVERSED, ['reverseTime' => $transaction->cancelTime], $payable);
    }

    private function status(array $body): array
    {
        $transaction = $this->store->find($this->provider, (string) $body['transId']);
        if (! $transaction) {
            return $this->failed($body, Errors::TRANSACTION_NOT_FOUND);
        }

        return $this->answer($body, $transaction, match ($transaction->state) {
            State::PERFORMED => Status::CONFIRMED,
            State::CANCELED, State::CANCELED_AFTER_PERFORM => Status::REVERSED,
            default => Status::CREATED,
        }, [
            'transTime' => $transaction->createTime,
            'confirmTime' => $transaction->performTime,
            'reverseTime' => $transaction->cancelTime,
        ]);
    }

    /** The thing being paid, or an error code. */
    private function payableFor(array $body, bool $lock = false): Payable|int
    {
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $account = $params[$this->source->accountKey()] ?? null;
        if (! is_scalar($account) || (string) $account === '') {
            return Errors::MISSING_PARAMS;
        }
        $payable = $this->source->find((string) $account);
        if ($payable && $lock) {
            $payable = $this->store->lock($payable);
        }

        return match (true) {
            ! $payable => Errors::ACCOUNT_NOT_FOUND,
            $payable->paymentState() === PayableState::Paid => Errors::ALREADY_PAID,
            $payable->paymentState() === PayableState::Closed => Errors::PAYMENT_CANCELED,
            default => $payable,
        };
    }

    /** What Uzum shows the payer: the account and the sum due. */
    private function data(?Payable $payable): array
    {
        return $payable ? [
            'account' => ['value' => $payable->paymentKey()],
            'amount' => ['value' => Money::tiyin($payable->paymentAmount())],
        ] : [];
    }

    private function answer(array $body, Transaction $transaction, string $status, array $times, ?Payable $payable = null): array
    {
        return [
            'serviceId' => $body['serviceId'],
            'transId' => $transaction->externalId,
            'status' => $status,
            ...$times,
            'data' => $this->data($payable ?? $transaction->payable),
            'amount' => Money::tiyin($transaction->amount),
        ];
    }

    private function failed(array $body, int $code): array
    {
        return [
            'serviceId' => $body['serviceId'] ?? null,
            'transId' => $body['transId'] ?? null,
            'timestamp' => $this->now(),
            'status' => Status::FAILED,
            'errorCode' => (string) $code,
        ];
    }

    private function now(): int
    {
        return ($this->now)();
    }
}
