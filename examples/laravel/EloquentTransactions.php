<?php

namespace App\Payments;

use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Transaction;

/**
 * payment_transactions for uzpay. One store per kind of payable (orders, invoices): each has its own cashbox,
 * and a callback to one never sees the transactions of the other. Reads lock the rows (callbacks run in atomic()).
 *
 *   new EloquentTransactions(Order::class)
 */
final class EloquentTransactions implements TransactionStore
{
    /** @var array<int|string, PaymentTransaction> models handed out, so update() needs no second read */
    private array $models = [];

    /** @param class-string<Model&Payable> $payableType */
    public function __construct(private string $payableType) {}

    public function atomic(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function find(string $provider, string $externalId): ?Transaction
    {
        return $this->wrap($this->query($provider)->where('external_id', $externalId)->lockForUpdate()->first());
    }

    public function findPrepared(string $provider, int|string $id, string $externalId): ?Transaction
    {
        return $this->wrap($this->query($provider)->whereKey($id)->where('external_id', $externalId)->lockForUpdate()->first());
    }

    public function create(string $provider, Payable $payable, array $attributes): Transaction
    {
        return $this->wrap(PaymentTransaction::create([...$this->link($payable), 'provider' => $provider, ...$attributes]));
    }

    public function firstOrCreate(string $provider, string $externalId, Payable $payable, array $attributes): Transaction
    {
        return $this->wrap(PaymentTransaction::firstOrCreate(
            ['provider' => $provider, 'external_id' => $externalId],
            [...$this->link($payable), ...$attributes],
        ));
    }

    public function update(Transaction $transaction, array $changes): Transaction
    {
        $model = $this->model($transaction);
        $model->update($changes);

        return $this->wrap($model);
    }

    public function hasOpen(string $provider, Payable $payable): bool
    {
        return PaymentTransaction::where($this->link($payable))->where('provider', $provider)->where('state', PaymentTransaction::CREATED)->exists();
    }

    public function latestOpen(string $provider, Payable $payable): ?Transaction
    {
        return $this->wrap($this->query($provider)->where($this->link($payable))->where('state', PaymentTransaction::CREATED)->latest('id')->lockForUpdate()->first());
    }

    public function lock(Payable $payable): ?Payable
    {
        return $payable instanceof Model ? $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->first() : $payable;
    }

    public function payable(Transaction $transaction, bool $lock = false): ?Payable
    {
        $payable = $this->model($transaction)->payable;

        return $lock && $payable ? $this->lock($payable) : $payable;
    }

    public function paidElsewhere(Transaction $transaction): bool
    {
        $model = $this->model($transaction);

        return PaymentTransaction::where('payable_type', $model->payable_type)->where('payable_id', $model->payable_id)
            ->where('state', PaymentTransaction::PERFORMED)->whereKeyNot($model->id)->exists();
    }

    public function between(string $provider, int $from, int $to): iterable
    {
        return $this->query($provider)->whereBetween('create_time', [$from, $to])->orderBy('create_time')->get()
            ->map(fn (PaymentTransaction $t) => $this->wrap($t))->all();
    }

    private function query(string $provider): Builder
    {
        return PaymentTransaction::with('payable')->where('provider', $provider)->where('payable_type', (new $this->payableType)->getMorphClass());
    }

    /** @return array{payable_type: string, payable_id: mixed} */
    private function link(Payable $payable): array
    {
        return ['payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey()];
    }

    private function model(Transaction $transaction): PaymentTransaction
    {
        return $this->models[$transaction->id] ??= PaymentTransaction::findOrFail($transaction->id);
    }

    private function wrap(?PaymentTransaction $model): ?Transaction
    {
        if (! $model) {
            return null;
        }
        $this->models[$model->id] = $model;

        return new Transaction(
            id: $model->id,
            externalId: (string) $model->external_id,
            amount: (string) $model->amount,
            state: (int) $model->state,
            reason: $model->reason,
            createTime: $model->create_time,
            performTime: $model->perform_time,
            cancelTime: $model->cancel_time,
            payload: $model->payload ?? [],
            payable: $model->relationLoaded('payable') ? $model->payable : null,
        );
    }
}
