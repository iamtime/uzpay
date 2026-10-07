<?php

namespace Uzpay\Payme;

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
 * Payme (Paycom) Merchant API: JSON-RPC over HTTP, Basic auth "Paycom:<key>", amounts in tiyin, times in
 * milliseconds. Give it the decoded request body and the Authorization header; send back the array it returns
 * as JSON with HTTP 200 (errors too - that is how Payme wants them).
 */
final class Merchant
{
    /** A created transaction expires after 12 hours. */
    public const TIMEOUT_MS = 43_200_000;

    private Closure $now;

    public function __construct(
        private TransactionStore $store,
        private PayableSource $source,
        private string $provider = 'payme',
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function handle(array $body, ?string $authorization, Gateway $gateway): array
    {
        $id = $body['id'] ?? null;

        if (! self::authorized($authorization, $gateway)) {
            return Errors::response($id, Errors::INSUFFICIENT_PRIVILEGE);
        }

        $method = (string) ($body['method'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        return match ($method) {
            'CheckPerformTransaction' => $this->checkPerform($id, $params),
            'CreateTransaction' => $this->create($id, $params),
            'PerformTransaction' => $this->perform($id, $params),
            'CancelTransaction' => $this->cancel($id, $params),
            'CheckTransaction' => $this->check($id, $params),
            'GetStatement' => $this->statement($id, $params),
            default => Errors::response($id, Errors::METHOD_NOT_FOUND, $method),
        };
    }

    /** In test mode Payme signs with the test key (the live one as a fallback). */
    public static function authorized(?string $authorization, Gateway $gateway): bool
    {
        $key = $gateway->testMode() ? ($gateway->credential('test_key') ?? $gateway->credential('key')) : $gateway->credential('key');
        if ($key === null || ! str_starts_with((string) $authorization, 'Basic ')) {
            return false;
        }
        [$login, $password] = array_pad(explode(':', (string) base64_decode(substr((string) $authorization, 6)), 2), 2, '');

        return $login === 'Paycom' && hash_equals($key, $password);
    }

    /**
     * The thing being paid, or an error code.
     */
    private function payableFor(array $params, bool $lock = false): Payable|int
    {
        $account = $params['account'][$this->source->accountKey()] ?? '';
        $payable = $this->source->find(is_scalar($account) ? (string) $account : '');
        if ($payable && $lock) {
            $payable = $this->store->lock($payable);
        }

        return match (true) {
            ! $payable => Errors::ORDER_NOT_FOUND,
            $payable->paymentState() !== PayableState::Open => Errors::ORDER_NOT_PAYABLE,
            ! is_numeric($params['amount'] ?? null) || Money::tiyin($payable->paymentAmount()) !== (int) $params['amount'] => Errors::WRONG_AMOUNT,
            default => $payable,
        };
    }

    private function payableError(mixed $id, int $code): array
    {
        return Errors::response($id, $code, $code === Errors::WRONG_AMOUNT ? 'amount' : $this->source->accountKey());
    }

    private function checkPerform(mixed $id, array $params): array
    {
        $payable = $this->payableFor($params);

        return is_int($payable)
            ? $this->payableError($id, $payable)
            : Errors::result($id, ['allow' => true, 'detail' => $payable->fiscalDetail()]);
    }

    private function create(mixed $id, array $params): array
    {
        return $this->store->atomic(function () use ($id, $params): array {
            $existing = $this->store->find($this->provider, (string) ($params['id'] ?? ''));

            if ($existing) {
                if ($existing->state !== State::CREATED) {
                    return Errors::response($id, Errors::CANNOT_PERFORM);
                }
                if ($this->expired($existing)) {
                    $this->store->update($existing, ['state' => State::CANCELED, 'reason' => 4, 'cancel_time' => $this->now()]);

                    return Errors::response($id, Errors::CANNOT_PERFORM);
                }

                return Errors::result($id, ['create_time' => $existing->createTime, 'transaction' => (string) $existing->id, 'state' => $existing->state]);
            }

            // Checked on the locked row: one transaction per payable, two parallel calls can't both pass.
            $payable = $this->payableFor($params, lock: true);
            if (is_int($payable)) {
                return $this->payableError($id, $payable);
            }
            if ($this->store->hasOpen($this->provider, $payable)) {
                return Errors::response($id, Errors::ORDER_BUSY, $this->source->accountKey());
            }

            $transaction = $this->store->create($this->provider, $payable, [
                'external_id' => (string) ($params['id'] ?? ''),
                'amount' => $payable->paymentAmount(),
                'state' => State::CREATED,
                'create_time' => $this->now(),
                'payload' => ['time' => $params['time'] ?? null],
            ]);

            return Errors::result($id, ['create_time' => $transaction->createTime, 'transaction' => (string) $transaction->id, 'state' => $transaction->state]);
        });
    }

    private function perform(mixed $id, array $params): array
    {
        return $this->store->atomic(function () use ($id, $params): array {
            $transaction = $this->store->find($this->provider, (string) ($params['id'] ?? ''));
            if (! $transaction) {
                return Errors::response($id, Errors::TRANSACTION_NOT_FOUND);
            }

            if ($transaction->state === State::CREATED) {
                if ($this->expired($transaction)) {
                    $this->store->update($transaction, ['state' => State::CANCELED, 'reason' => 4, 'cancel_time' => $this->now()]);

                    return Errors::response($id, Errors::CANNOT_PERFORM);
                }

                // The order may have been canceled, changed or paid another way since CreateTransaction: then it is not paid.
                $payable = $this->store->payable($transaction, lock: true);
                if (! $payable || $this->store->paidElsewhere($transaction) || $payable->paymentState() !== PayableState::Open
                    || ! Money::same($payable->paymentAmount(), $transaction->amount)) {
                    $this->store->update($transaction, ['state' => State::CANCELED, 'reason' => 5, 'cancel_time' => $this->now()]);

                    return Errors::response($id, Errors::CANNOT_PERFORM);
                }

                $transaction = $this->store->update($transaction, ['state' => State::PERFORMED, 'perform_time' => $this->now()]);
                $payable->markPaidVia($this->provider);
            }

            if ($transaction->state !== State::PERFORMED) {
                return Errors::response($id, Errors::CANNOT_PERFORM);
            }

            return Errors::result($id, ['transaction' => (string) $transaction->id, 'perform_time' => $transaction->performTime, 'state' => $transaction->state]);
        });
    }

    private function cancel(mixed $id, array $params): array
    {
        return $this->store->atomic(function () use ($id, $params): array {
            $transaction = $this->store->find($this->provider, (string) ($params['id'] ?? ''));
            if (! $transaction) {
                return Errors::response($id, Errors::TRANSACTION_NOT_FOUND);
            }
            $reason = isset($params['reason']) ? (int) $params['reason'] : null;

            if ($transaction->state === State::CREATED) {
                $transaction = $this->store->update($transaction, ['state' => State::CANCELED, 'reason' => $reason, 'cancel_time' => $this->now()]);
            } elseif ($transaction->state === State::PERFORMED) {
                $payable = $this->store->payable($transaction, lock: true);
                if (! $payable?->canRefund()) {
                    return Errors::response($id, Errors::CANNOT_CANCEL);
                }
                $transaction = $this->store->update($transaction, ['state' => State::CANCELED_AFTER_PERFORM, 'reason' => $reason, 'cancel_time' => $this->now()]);
                $payable->markRefunded();
            }

            return Errors::result($id, ['transaction' => (string) $transaction->id, 'cancel_time' => $transaction->cancelTime, 'state' => $transaction->state]);
        });
    }

    private function check(mixed $id, array $params): array
    {
        $transaction = $this->store->find($this->provider, (string) ($params['id'] ?? ''));

        return $transaction
            ? Errors::result($id, [
                'create_time' => $transaction->createTime,
                'perform_time' => $transaction->performTime ?? 0,
                'cancel_time' => $transaction->cancelTime ?? 0,
                'transaction' => (string) $transaction->id,
                'state' => $transaction->state,
                'reason' => $transaction->reason,
            ])
            : Errors::response($id, Errors::TRANSACTION_NOT_FOUND);
    }

    private function statement(mixed $id, array $params): array
    {
        $transactions = [];
        foreach ($this->store->between($this->provider, (int) ($params['from'] ?? 0), (int) ($params['to'] ?? 0)) as $t) {
            $transactions[] = [
                'id' => $t->externalId,
                'time' => $t->payload['time'] ?? $t->createTime,
                'amount' => Money::tiyin($t->amount),
                'account' => [$this->source->accountKey() => $t->payable?->paymentKey()],
                'create_time' => $t->createTime,
                'perform_time' => $t->performTime ?? 0,
                'cancel_time' => $t->cancelTime ?? 0,
                'transaction' => (string) $t->id,
                'state' => $t->state,
                'reason' => $t->reason,
            ];
        }

        return Errors::result($id, ['transactions' => $transactions]);
    }

    private function expired(Transaction $transaction): bool
    {
        return $this->now() - (int) $transaction->createTime > self::TIMEOUT_MS;
    }

    private function now(): int
    {
        return ($this->now)();
    }
}
