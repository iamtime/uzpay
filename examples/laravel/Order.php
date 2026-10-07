<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Uzpay\Contracts\Payable;
use Uzpay\Fiscal\Receipt;
use Uzpay\PayableState;

/**
 * An order any of the five providers can pay. Columns used: number, total (decimal 15,2), status, paid_via, shipped_at,
 * delivery_price, discount; items: title, price, quantity, mxik_code, package_code, vat_percent.
 */
class Order extends Model implements Payable
{
    protected $fillable = ['number', 'total', 'status', 'paid_via', 'delivery_price', 'discount'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentKey(): string
    {
        return (string) $this->number;
    }

    public function paymentAmount(): string
    {
        return (string) $this->total;
    }

    public function paymentState(): PayableState
    {
        return match ($this->status) {
            'new' => PayableState::Open,
            'paid' => PayableState::Paid,
            default => PayableState::Closed,
        };
    }

    public function markPaidVia(string $provider): void
    {
        $this->update(['status' => 'paid', 'paid_via' => $provider]);
        // event(new OrderPaid($this)) - notify the shop and the buyer after the transaction commits.
    }

    public function canRefund(): bool
    {
        return $this->shipped_at === null;
    }

    public function markRefunded(): void
    {
        $this->update(['status' => 'refunded']);
    }

    public function fiscalDetail(): array
    {
        $receipt = Receipt::make();
        foreach ($this->items as $item) {
            $receipt->item($item->title, (string) $item->price, (float) $item->quantity, $item->mxik_code, $item->package_code, (int) $item->vat_percent);
        }

        return $receipt->shipping('Доставка', (string) ($this->delivery_price ?? 0))
            ->discount('Скидка', (string) ($this->discount ?? 0))
            ->toArray();
    }
}
