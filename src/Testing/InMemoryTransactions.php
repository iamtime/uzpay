<?php

namespace Uzpay\Testing;

use Throwable;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Transaction;
use Uzpay\TransactionState;

/**
 * A TransactionStore in an array: for tests and for trying the protocol without a database.
 * atomic() rolls the transactions back when the callback throws.
 */
final class InMemoryTransactions implements TransactionStore
{
    /** @var array<int, array{provider: string, payable: Payable, attributes: array<string, mixed>}> */
    private array $rows = [];

    private int $nextId = 1;

    public function atomic(callable $callback): mixed
    {
        $rows = $this->rows;
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->rows = $rows;
            throw $e;
        }
    }

    public function find(string $provider, string $externalId): ?Transaction
    {
        foreach ($this->rows as $id => $row) {
            if ($row['provider'] === $provider && $row['attributes']['external_id'] === $externalId) {
                return $this->transaction($id);
            }
        }

        return null;
    }

    public function findPrepared(string $provider, int|string $id, string $externalId): ?Transaction
    {
        $row = $this->rows[(int) $id] ?? null;

        return $row && $row['provider'] === $provider && $row['attributes']['external_id'] === $externalId ? $this->transaction((int) $id) : null;
    }

    public function create(string $provider, Payable $payable, array $attributes): Transaction
    {
        $id = $this->nextId++;
        $this->rows[$id] = ['provider' => $provider, 'payable' => $payable, 'attributes' => $attributes + ['reason' => null, 'perform_time' => null, 'cancel_time' => null, 'payload' => []]];

        return $this->transaction($id);
    }

    public function firstOrCreate(string $provider, string $externalId, Payable $payable, array $attributes): Transaction
    {
        return $this->find($provider, $externalId) ?? $this->create($provider, $payable, ['external_id' => $externalId] + $attributes);
    }

    public function update(Transaction $transaction, array $changes): Transaction
    {
        $this->rows[(int) $transaction->id]['attributes'] = $changes + $this->rows[(int) $transaction->id]['attributes'];

        return $this->transaction((int) $transaction->id);
    }

    public function hasOpen(string $provider, Payable $payable): bool
    {
        foreach ($this->rows as $row) {
            if ($row['provider'] === $provider && $row['payable']->paymentKey() === $payable->paymentKey()
                && $row['attributes']['state'] === TransactionState::CREATED) {
                return true;
            }
        }

        return false;
    }

    public function latestOpen(string $provider, Payable $payable): ?Transaction
    {
        foreach (array_reverse($this->rows, true) as $id => $row) {
            if ($row['provider'] === $provider && $row['payable']->paymentKey() === $payable->paymentKey()
                && $row['attributes']['state'] === TransactionState::CREATED) {
                return $this->transaction($id);
            }
        }

        return null;
    }

    public function lock(Payable $payable): ?Payable
    {
        return $payable;
    }

    public function payable(Transaction $transaction, bool $lock = false): ?Payable
    {
        return $this->rows[(int) $transaction->id]['payable'] ?? null;
    }

    public function paidElsewhere(Transaction $transaction): bool
    {
        $key = $this->rows[(int) $transaction->id]['payable']->paymentKey();
        foreach ($this->rows as $id => $row) {
            if ($id !== (int) $transaction->id && $row['payable']->paymentKey() === $key && $row['attributes']['state'] === TransactionState::PERFORMED) {
                return true;
            }
        }

        return false;
    }

    public function between(string $provider, int $from, int $to): iterable
    {
        $found = [];
        foreach ($this->rows as $id => $row) {
            $time = (int) ($row['attributes']['create_time'] ?? 0);
            if ($row['provider'] === $provider && $time >= $from && $time <= $to) {
                $found[] = $this->transaction($id);
            }
        }
        usort($found, fn (Transaction $a, Transaction $b) => $a->createTime <=> $b->createTime);

        return $found;
    }

    /** @return list<Transaction> every transaction, for assertions */
    public function all(): array
    {
        return array_map(fn (int $id) => $this->transaction($id), array_keys($this->rows));
    }

    private function transaction(int $id): Transaction
    {
        $a = $this->rows[$id]['attributes'];

        return new Transaction(
            id: $id,
            externalId: (string) $a['external_id'],
            amount: (string) $a['amount'],
            state: (int) $a['state'],
            reason: $a['reason'] ?? null,
            createTime: $a['create_time'] ?? null,
            performTime: $a['perform_time'] ?? null,
            cancelTime: $a['cancel_time'] ?? null,
            payload: $a['payload'] ?? [],
            payable: $this->rows[$id]['payable'],
        );
    }
}
