<?php

namespace Uzpay\Contracts;

use Uzpay\Transaction;

/**
 * Where transactions live (a database table in an app). Every callback runs inside atomic(); reads inside it lock
 * the rows they return, so two parallel callbacks for one order see each other.
 *
 * One store serves one PayableSource: it never returns transactions of other kinds of payables.
 * Attribute names are external_id, amount, state, reason, create_time, perform_time, cancel_time, payload.
 */
interface TransactionStore
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function atomic(callable $callback): mixed;

    public function find(string $provider, string $externalId): ?Transaction;

    /** Click Complete: our id from Prepare plus Click's id must both match. */
    public function findPrepared(string $provider, int|string $id, string $externalId): ?Transaction;

    /** @param array<string, mixed> $attributes */
    public function create(string $provider, Payable $payable, array $attributes): Transaction;

    /**
     * Click repeats Prepare with the same click_trans_id: return that transaction instead of a second one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreate(string $provider, string $externalId, Payable $payable, array $attributes): Transaction;

    /** @param array<string, mixed> $changes */
    public function update(Transaction $transaction, array $changes): Transaction;

    /** A created, not yet performed transaction of this provider exists for the payable. */
    public function hasOpen(string $provider, Payable $payable): bool;

    /** The newest of those (locked): Octo sends the payer back to its page instead of opening a second one. */
    public function latestOpen(string $provider, Payable $payable): ?Transaction;

    /** The payable re-read under a row lock; null if it is gone. */
    public function lock(Payable $payable): ?Payable;

    public function payable(Transaction $transaction, bool $lock = false): ?Payable;

    /** Another transaction (any provider) has already taken the money for the same payable. */
    public function paidElsewhere(Transaction $transaction): bool;

    /**
     * Transactions created between two Unix-millisecond times, oldest first, with their payables.
     *
     * @return iterable<Transaction>
     */
    public function between(string $provider, int $from, int $to): iterable;
}
