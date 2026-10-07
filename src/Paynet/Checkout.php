<?php

namespace Uzpay\Paynet;

/**
 * Link that opens the merchant in the Paynet app with the payable's key and the sum filled in.
 */
final class Checkout
{
    public const URL = 'https://app.paynet.uz/';

    /** @param string $merchant the "m" id Paynet gave for the link */
    public static function url(string $merchant, string $account, ?int $tiyin = null): string
    {
        return self::URL.'?'.http_build_query(array_filter(['m' => $merchant, 'c' => $account, 'a' => $tiyin], fn ($v) => $v !== null));
    }
}
