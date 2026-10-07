<?php

namespace Uzpay\Contracts;

/**
 * Where a callback looks for the thing being paid.
 */
interface PayableSource
{
    /** Account field name in the provider's cabinet (Payme "account.<key>", Uzum "params.<key>", Paynet "fields.<key>"), e.g. "order_id". */
    public function accountKey(): string;

    public function find(string $key): ?Payable;
}
