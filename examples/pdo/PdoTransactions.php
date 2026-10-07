<?php

use Uzpay\Contracts\Payable;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Transaction;
use Uzpay\TransactionState;

/**
 * The payment_transactions table for uzpay. Every provider call runs in atomic() - one database
 * transaction; reads inside it lock the rows, so two callbacks for one order at the same moment wait for each other.
 */
final class PdoTransactions implements TransactionStore
{
    public function __construct(private PDO $db, private Orders $orders) {}

    public function atomic(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function find(string $provider, string $externalId): ?Transaction
    {
        return $this->one('provider = ? AND external_id = ?', [$provider, $externalId]);
    }

    public function findPrepared(string $provider, int|string $id, string $externalId): ?Transaction
    {
        return $this->one('provider = ? AND id = ? AND external_id = ?', [$provider, $id, $externalId]);
    }

    public function create(string $provider, Payable $payable, array $attributes): Transaction
    {
        $row = ['provider' => $provider, 'order_id' => $this->orderId($payable)] + $attributes;
        $row['payload'] = json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO payment_transactions ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')
            ->execute(array_values($row));

        return $this->one('id = ?', [(int) $this->db->lastInsertId()]);
    }

    public function firstOrCreate(string $provider, string $externalId, Payable $payable, array $attributes): Transaction
    {
        return $this->find($provider, $externalId) ?? $this->create($provider, $payable, ['external_id' => $externalId] + $attributes);
    }

    public function update(Transaction $transaction, array $changes): Transaction
    {
        if (array_key_exists('payload', $changes)) {
            $changes['payload'] = json_encode($changes['payload'] ?? [], JSON_UNESCAPED_UNICODE);
        }
        $set = implode(', ', array_map(fn (string $column) => $column.' = ?', array_keys($changes)));
        $this->db->prepare('UPDATE payment_transactions SET '.$set.' WHERE id = ?')->execute([...array_values($changes), $transaction->id]);

        return $this->one('id = ?', [$transaction->id]);
    }

    public function hasOpen(string $provider, Payable $payable): bool
    {
        return $this->exists('provider = ? AND order_id = ? AND state = ?', [$provider, $this->orderId($payable), TransactionState::CREATED]);
    }

    public function latestOpen(string $provider, Payable $payable): ?Transaction
    {
        return $this->one('provider = ? AND order_id = ? AND state = ? ORDER BY id DESC LIMIT 1', [$provider, $this->orderId($payable), TransactionState::CREATED]);
    }

    public function lock(Payable $payable): ?Payable
    {
        return $this->orders->byId($this->orderId($payable), lock: true);
    }

    public function payable(Transaction $transaction, bool $lock = false): ?Payable
    {
        return $transaction->payable instanceof Order ? $this->orders->byId($transaction->payable->id(), $lock) : null;
    }

    public function paidElsewhere(Transaction $transaction): bool
    {
        return $this->exists('order_id = ? AND state = ? AND id <> ?', [$this->orderId($transaction->payable), TransactionState::PERFORMED, $transaction->id]);
    }

    public function between(string $provider, int $from, int $to): iterable
    {
        return $this->all('provider = ? AND create_time BETWEEN ? AND ? ORDER BY create_time', [$provider, $from, $to]);
    }

    private function orderId(?Payable $payable): int
    {
        return $payable instanceof Order ? $payable->id() : throw new InvalidArgumentException('Not an order of this shop');
    }

    private function one(string $where, array $bindings): ?Transaction
    {
        return $this->all($where.Lock::clause($this->db), $bindings)[0] ?? null;
    }

    private function exists(string $where, array $bindings): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM payment_transactions WHERE '.$where);
        $statement->execute($bindings);

        return (bool) $statement->fetchColumn();
    }

    /** @return list<Transaction> */
    private function all(string $where, array $bindings): array
    {
        $statement = $this->db->prepare('SELECT * FROM payment_transactions WHERE '.$where);
        $statement->execute($bindings);

        return array_map(fn (array $row) => new Transaction(
            id: (int) $row['id'],
            externalId: $row['external_id'],
            amount: (string) $row['amount'],
            state: (int) $row['state'],
            reason: $row['reason'] === null ? null : (int) $row['reason'],
            createTime: $row['create_time'] === null ? null : (int) $row['create_time'],
            performTime: $row['perform_time'] === null ? null : (int) $row['perform_time'],
            cancelTime: $row['cancel_time'] === null ? null : (int) $row['cancel_time'],
            payload: json_decode((string) $row['payload'], true) ?: [],
            payable: $this->orders->byId((int) $row['order_id']),
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
