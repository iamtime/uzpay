<?php

namespace App\Payments;

use App\Models\Order;
use Uzpay\Contracts\PayableSource;

/**
 * Orders by the number the provider sends back. "order_id" is the account field set up at Payme, Uzum and Paynet.
 */
final class Orders implements PayableSource
{
    public function accountKey(): string
    {
        return 'order_id';
    }

    public function find(string $key): ?Order
    {
        return Order::with('items')->where('number', $key)->first();
    }
}
