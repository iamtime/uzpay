<?php

use Uzpay\Contracts\PayableSource;

/**
 * Finds an order by the number the provider sends back. "order_id" is the account field set up at Payme, Uzum and Paynet.
 */
final class Orders implements PayableSource
{
    public function __construct(private PDO $db) {}

    public function accountKey(): string
    {
        return 'order_id';
    }

    public function find(string $key): ?Order
    {
        return $this->one('number = ?', $key);
    }

    public function byId(int $id, bool $lock = false): ?Order
    {
        return $this->one('id = ?', $id, $lock);
    }

    private function one(string $where, string|int $value, bool $lock = false): ?Order
    {
        $statement = $this->db->prepare('SELECT * FROM orders WHERE '.$where.Lock::clause($this->db, $lock));
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? new Order($this->db, $row) : null;
    }
}

/**
 * Row locks: "FOR UPDATE" on MySQL and PostgreSQL. SQLite locks the whole database for a write transaction
 * anyway, so it needs none.
 */
final class Lock
{
    public static function clause(PDO $db, bool $lock = true): string
    {
        return $lock && in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
    }
}
