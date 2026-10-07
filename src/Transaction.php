<?php

namespace Uzpay;

use Uzpay\Contracts\Payable;

/**
 * A provider transaction as the store gives it. Times are Unix milliseconds (Payme's clock).
 */
final class Transaction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $externalId,
        public readonly string $amount,
        public readonly int $state,
        public readonly ?int $reason = null,
        public readonly ?int $createTime = null,
        public readonly ?int $performTime = null,
        public readonly ?int $cancelTime = null,
        public readonly array $payload = [],
        /** What it pays, when the store has it at hand. */
        public readonly ?Payable $payable = null,
    ) {}
}
