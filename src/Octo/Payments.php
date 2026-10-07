<?php

namespace Uzpay\Octo;

use Closure;
use Throwable;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\Transaction;
use Uzpay\TransactionState as State;

/**
 * Octo is called by us, not the other way round: start() records a transaction and asks Octo for the payment
 * page, refund() returns the money. The payable becomes paid only by a notification (see Notifications).
 *
 * The transaction's external_id is our shop_transaction_id (the payable's key plus a random tail: every
 * attempt to pay is a new one); Octo's octo_payment_UUID and page are kept in its payload.
 */
final class Payments
{
    private Closure $now;

    public function __construct(
        private TransactionStore $store,
        private Client $client,
        private string $provider = 'octo',
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * The URL of Octo's payment page for the payable.
     *
     * @param  array<string, mixed>  $options  for Client::prepare(); "description" replaces "Заказ №<key>"
     *
     * @throws OctoException when the payable is not open or Octo refuses
     */
    public function start(Payable $payable, string $returnUrl, ?string $notifyUrl = null, array $options = []): string
    {
        $reused = null;
        $transaction = $this->store->atomic(function () use ($payable, $options, &$reused): Transaction {
            $payable = $this->store->lock($payable);
            if (! $payable || $payable->paymentState() !== PayableState::Open) {
                throw new OctoException('Not payable');
            }
            // One page per payable, like one open transaction in Payme: a second tab or «back» and «pay» again open
            // the same page, so the payer can't put money on hold twice. A new one only for a new sum or a page
            // about to expire (Octo's ttl, minutes).
            $open = $this->store->latestOpen($this->provider, $payable);
            $fresh = $this->now() - (max(3, (int) ($options['ttl'] ?? 15)) - 2) * 60_000;
            if ($open && (string) ($open->payload['url'] ?? '') !== '' && Money::same($open->amount, $payable->paymentAmount()) && (int) $open->createTime > $fresh) {
                return $reused = $open;
            }

            return $this->store->create($this->provider, $payable, [
                'external_id' => $payable->paymentKey().'-'.bin2hex(random_bytes(4)),
                'amount' => $payable->paymentAmount(),
                'state' => State::CREATED,
                'create_time' => $this->now(),
                'payload' => [],
            ]);
        });

        if ($reused) {
            return (string) $reused->payload['url'];
        }

        $description = (string) ($options['description'] ?? 'Заказ №'.$payable->paymentKey());
        unset($options['description']);

        try {
            $data = $this->client->prepare($transaction->externalId, $transaction->amount, $description, $returnUrl, $notifyUrl, $options);
        } catch (Throwable $e) {
            $this->store->atomic(fn () => $this->store->update($transaction, ['state' => State::CANCELED, 'cancel_time' => $this->now(), 'payload' => ['error' => $e->getMessage()]]));

            throw $e;
        }

        $this->store->atomic(fn () => $this->store->update($transaction, ['payload' => [
            'uuid' => (string) ($data['octo_payment_UUID'] ?? ''),
            'url' => (string) ($data['octo_pay_url'] ?? ''),
        ]]));

        return (string) ($data['octo_pay_url'] ?? '');
    }

    /**
     * Return the money of a paid transaction to the card and mark the payable refunded.
     *
     * @throws OctoException when the transaction is not paid, the payable may not be refunded or Octo refuses
     */
    public function refund(string $shopTransactionId): Transaction
    {
        return $this->store->atomic(function () use ($shopTransactionId): Transaction {
            $transaction = $this->store->find($this->provider, $shopTransactionId);
            if (! $transaction || $transaction->state !== State::PERFORMED) {
                throw new OctoException('The transaction is not paid');
            }
            $payable = $this->store->payable($transaction, lock: true);
            // A duplicate payment (unclaimed) is not the payable's money: it goes back whatever the payable's state.
            $duplicate = ! empty($transaction->payload['unclaimed']) || $this->store->paidElsewhere($transaction);
            if (! $duplicate && ! $payable?->canRefund()) {
                throw new OctoException('The payment can not be refunded');
            }

            // Inside the lock: a second refund of the same payment waits and then sees it canceled.
            $refund = $this->client->refund((string) ($transaction->payload['uuid'] ?? ''), $transaction->amount, $transaction->externalId.'-refund');
            if (($refund['status'] ?? null) === Status::FAILED) {
                throw new OctoException('Octo refused the refund');
            }

            $transaction = $this->store->update($transaction, [
                'state' => State::CANCELED_AFTER_PERFORM,
                'cancel_time' => $this->now(),
                'payload' => $transaction->payload + ['refund_id' => $refund['refund_id'] ?? null],
            ]);
            if (! $duplicate) {
                $payable?->markRefunded();
            }

            return $transaction;
        });
    }

    private function now(): int
    {
        return ($this->now)();
    }
}
