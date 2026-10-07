<?php

namespace Uzpay\Click;

use Closure;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\PayableSource;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\TransactionState as State;

/**
 * Click SHOP API: Prepare (action=0) and Complete (action=1) callbacks, both to one URL. merchant_trans_id is
 * the payable's key. Give it the request fields; send back the array it returns as JSON.
 */
final class Shop
{
    public const PREPARE = 0;

    public const COMPLETE = 1;

    private Closure $now;

    public function __construct(
        private TransactionStore $store,
        private PayableSource $source,
        private string $provider = 'click',
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function handle(array $params, Gateway $gateway): array
    {
        $action = (int) ($params['action'] ?? -1);

        if (! in_array($action, [self::PREPARE, self::COMPLETE], true)) {
            return self::answer($params, -3, 'Action not found');
        }
        if (! Signature::valid($params, $gateway->credential('secret_key'))) {
            return self::answer($params, -1, 'SIGN CHECK FAILED!');
        }
        if ((string) ($params['service_id'] ?? '') !== (string) $gateway->credential('service_id')) {
            return self::answer($params, -8, 'Error in request from click');
        }
        if (! is_numeric($params['amount'] ?? null)) {
            return self::answer($params, -2, 'Incorrect parameter amount');
        }

        return $this->store->atomic(fn (): array => $action === self::PREPARE ? $this->prepare($params) : $this->complete($params));
    }

    private function prepare(array $p): array
    {
        $payable = $this->source->find((string) ($p['merchant_trans_id'] ?? ''));
        // Checks below run on the locked row: a payment finishing at the same moment is seen.
        $payable = $payable ? $this->store->lock($payable) : null;

        if (! $payable) {
            return self::answer($p, -5, 'Order does not exist');
        }
        if ($payable->paymentState() === PayableState::Paid) {
            return self::answer($p, -4, 'Already paid');
        }
        if ($payable->paymentState() === PayableState::Closed) {
            return self::answer($p, -9, 'Transaction cancelled');
        }
        if (! Money::same($payable->paymentAmount(), (string) $p['amount'])) {
            return self::answer($p, -2, 'Incorrect parameter amount');
        }

        $transaction = $this->store->firstOrCreate($this->provider, (string) ($p['click_trans_id'] ?? ''), $payable, [
            'amount' => $payable->paymentAmount(),
            'state' => State::CREATED,
            'payload' => $p,
        ]);

        return self::answer($p, 0, 'Success', ['merchant_prepare_id' => $transaction->id]);
    }

    private function complete(array $p): array
    {
        $transaction = $this->store->findPrepared($this->provider, (int) ($p['merchant_prepare_id'] ?? 0), (string) ($p['click_trans_id'] ?? ''));
        if (! $transaction) {
            return self::answer($p, -6, 'Transaction does not exist');
        }
        $payable = $this->store->payable($transaction, lock: true);
        if ($transaction->state === State::PERFORMED || ! $payable || $payable->paymentState() === PayableState::Paid || $this->store->paidElsewhere($transaction)) {
            return self::answer($p, -4, 'Already paid', ['merchant_confirm_id' => $transaction->id]);
        }
        if (! Money::same($transaction->amount, (string) $p['amount'])) {
            return self::answer($p, -2, 'Incorrect parameter amount');
        }

        // Click reports its own failure (e.g. not enough money) with error < 0: cancel on our side.
        // An order closed or changed since Prepare is not paid either.
        $changed = $payable->paymentState() !== PayableState::Open || ! Money::same($payable->paymentAmount(), $transaction->amount);
        if ((int) ($p['error'] ?? 0) < 0 || $transaction->state === State::CANCELED || $changed) {
            $this->store->update($transaction, ['state' => State::CANCELED]);

            return self::answer($p, -9, 'Transaction cancelled');
        }

        $this->store->update($transaction, ['state' => State::PERFORMED, 'perform_time' => ($this->now)()]);
        $payable->markPaidVia($this->provider);

        return self::answer($p, 0, 'Success', ['merchant_confirm_id' => $transaction->id]);
    }

    /** @return array<string, mixed> */
    private static function answer(array $p, int $error, string $note, array $extra = []): array
    {
        return [
            'click_trans_id' => $p['click_trans_id'] ?? null,
            'merchant_trans_id' => $p['merchant_trans_id'] ?? null,
            ...$extra,
            'error' => $error,
            'error_note' => $note,
        ];
    }
}
