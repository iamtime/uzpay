<?php

namespace Uzpay\Contracts;

use Uzpay\PayableState;

/**
 * Something a buyer pays through a provider: an order, an invoice. The protocol code speaks only to this.
 */
interface Payable
{
    /** Number the provider shows and sends back (order number, invoice number). */
    public function paymentKey(): string;

    /** Sum in so'm, a decimal string: "125000.00". */
    public function paymentAmount(): string;

    public function paymentState(): PayableState;

    /** Called once, inside the store's transaction, when the provider has taken the money. */
    public function markPaidVia(string $provider): void;

    /** Payment may still be reversed (e.g. the order is not completed yet). */
    public function canRefund(): bool;

    public function markRefunded(): void;

    /**
     * The fiscal receipt (Fiscal\Receipt::toArray()): Payme's "detail" in CheckPerformTransaction and in Subscribe
     * receipts, and the source of Octo\Basket. Return [] when the order is not fiscalised.
     *
     * @return array<string, mixed>
     */
    public function fiscalDetail(): array;
}
