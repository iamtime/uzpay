<?php

namespace Uzpay\Octo;

use Closure;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\Transaction;
use Uzpay\TransactionState as State;

/**
 * Octo's notifications to notify_url. Give it the decoded body; send back the array it returns as JSON
 * with Notifications::httpStatus() of it.
 *
 * waiting_for_capture: the money is held. The payable is checked under the lock and the answer tells Octo to
 * capture it (the payable is paid now) or to cancel (the order was closed, changed or paid another way).
 * canceled / failed / refunded after a capture: the money went back, the payable is marked refunded.
 */
final class Notifications
{
    private Closure $now;

    public function __construct(
        private TransactionStore $store,
        private string $provider = 'octo',
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function handle(array $body, Gateway $gateway): array
    {
        if (! Signature::valid($body, $gateway->credential('unique_key'))) {
            return ['error' => 'signature'];
        }

        return $this->store->atomic(fn (): array => $this->apply($body));
    }

    public static function httpStatus(array $answer): int
    {
        return isset($answer['error']) ? 400 : 200;
    }

    private function apply(array $body): array
    {
        $transaction = $this->store->find($this->provider, (string) ($body['shop_transaction_id'] ?? ''));
        $uuid = (string) ($body['octo_payment_UUID'] ?? '');
        if (! $transaction || (($transaction->payload['uuid'] ?? '') !== '' && $transaction->payload['uuid'] !== $uuid)) {
            return ['error' => 'transaction'];
        }
        // The answer to prepare_payment may have been lost: the id arrives here as well.
        $payload = array_merge($transaction->payload, ['uuid' => $uuid, 'status' => (string) ($body['status'] ?? '')], array_filter([
            'card' => $body['maskedPan'] ?? null,
            'card_vendor' => $body['card_vendor'] ?? null,
            'rrn' => $body['rrn'] ?? null,
        ], fn ($v) => $v !== null));

        return match ($payload['status']) {
            Status::WAITING_FOR_CAPTURE => $this->capture($transaction, $body, $payload),
            Status::SUCCEEDED => $this->succeeded($transaction, $body, $payload),
            Status::CANCELED, Status::FAILED, Status::REFUNDED => $this->canceled($transaction, $payload),
            default => $this->keep($transaction, $payload),
        };
    }

    private function capture(Transaction $transaction, array $body, array $payload): array
    {
        if ($transaction->state === State::PERFORMED) {
            return ['accept_status' => 'capture', 'final_amount' => Client::sum($transaction->amount)];
        }
        $payable = $transaction->state === State::CREATED ? $this->payable($transaction, $body) : null;
        if (! $payable) {
            if ($transaction->state === State::CREATED) {
                $this->store->update($transaction, ['state' => State::CANCELED, 'reason' => 5, 'cancel_time' => $this->now(), 'payload' => $payload]);
            }

            return ['accept_status' => 'cancel'];
        }

        $this->store->update($transaction, ['state' => State::PERFORMED, 'perform_time' => $this->now(), 'payload' => $payload]);
        $payable->markPaidVia($this->provider);

        return ['accept_status' => 'capture', 'final_amount' => Client::sum($transaction->amount)];
    }

    /** Normally after our capture. A one-stage payment (set up in the Octo cabinet) gets paid here. */
    private function succeeded(Transaction $transaction, array $body, array $payload): array
    {
        if ($transaction->state === State::CREATED) {
            $payable = $this->payable($transaction, $body);
            // Money taken for an order that can't be paid any more: kept as performed for the refund, flagged.
            $this->store->update($transaction, ['state' => State::PERFORMED, 'perform_time' => $this->now(), 'payload' => $payload + ($payable ? [] : ['unclaimed' => true])]);
            $payable?->markPaidVia($this->provider);

            return ['status' => 'ok'];
        }

        return $this->keep($transaction, $payload);
    }

    private function canceled(Transaction $transaction, array $payload): array
    {
        if ($transaction->state === State::CREATED) {
            $this->store->update($transaction, ['state' => State::CANCELED, 'cancel_time' => $this->now(), 'payload' => $payload]);
        } elseif ($transaction->state === State::PERFORMED) {
            // Octo did not keep the money (capture failed, hold expired, refunded in its cabinet). A duplicate payment
            // (unclaimed) never paid the payable: only it goes back, the payable stays paid by the other one.
            $payable = $this->store->payable($transaction, lock: true);
            $this->store->update($transaction, ['state' => State::CANCELED_AFTER_PERFORM, 'cancel_time' => $this->now(), 'payload' => $payload]);
            if (empty($transaction->payload['unclaimed']) && ! $this->store->paidElsewhere($transaction)) {
                $payable?->markRefunded();
            }
        }

        return ['status' => 'ok'];
    }

    private function keep(Transaction $transaction, array $payload): array
    {
        $this->store->update($transaction, ['payload' => $payload]);

        return ['status' => 'ok'];
    }

    /** The payable, locked, if it may be paid by this transaction now. */
    private function payable(Transaction $transaction, array $body): ?Payable
    {
        $payable = $this->store->payable($transaction, lock: true);
        $sum = $body['total_sum'] ?? $transaction->amount;

        return $payable && ! $this->store->paidElsewhere($transaction) && $payable->paymentState() === PayableState::Open
            && Money::same($payable->paymentAmount(), $transaction->amount) && is_numeric($sum) && Money::same($transaction->amount, $sum)
            ? $payable : null;
    }

    private function now(): int
    {
        return ($this->now)();
    }
}
