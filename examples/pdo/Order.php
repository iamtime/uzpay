<?php

use Uzpay\Contracts\Payable;
use Uzpay\Fiscal\Receipt;
use Uzpay\PayableState;

/**
 * A row of the orders table as something Payme / Click can pay.
 */
final class Order implements Payable
{
    /** @param array<string, mixed> $row */
    public function __construct(private PDO $db, public readonly array $row) {}

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function paymentKey(): string
    {
        return (string) $this->row['number'];
    }

    public function paymentAmount(): string
    {
        return (string) $this->row['total'];
    }

    public function paymentState(): PayableState
    {
        return match ($this->row['status']) {
            'new' => PayableState::Open,
            'paid' => PayableState::Paid,
            default => PayableState::Closed,
        };
    }

    public function markPaidVia(string $provider): void
    {
        // Runs inside the store's transaction: the payment row and the order change together.
        $this->db->prepare("UPDATE orders SET status = 'paid', paid_via = ? WHERE id = ?")->execute([$provider, $this->id()]);
        // Here: send the order to the warehouse, notify the buyer...
    }

    public function canRefund(): bool
    {
        return ! $this->row['shipped'];
    }

    public function markRefunded(): void
    {
        $this->db->prepare("UPDATE orders SET status = 'refunded' WHERE id = ?")->execute([$this->id()]);
    }

    public function fiscalDetail(): array
    {
        $receipt = Receipt::make();
        $items = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $items->execute([$this->id()]);
        foreach ($items as $item) {
            $receipt->item($item['title'], $item['price'], (float) $item['quantity'], $item['mxik_code'], $item['package_code'], (int) $item['vat_percent']);
        }

        return $receipt->toArray();
    }
}
