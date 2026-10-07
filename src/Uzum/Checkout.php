<?php

namespace Uzpay\Uzum;

/**
 * Link to the Uzum Bank payment page (open-service): the payer pays in the app or on the web, Uzum calls
 * check / create / confirm with params.<account key> and comes back to redirectUrl.
 */
final class Checkout
{
    public const URL = 'https://www.uzumbank.uz/open-service';

    public static function url(string $serviceId, string $accountKey, string $account, int $tiyin, ?string $returnUrl = null): string
    {
        return self::URL.'?'.http_build_query(array_filter([
            'serviceId' => $serviceId,
            $accountKey => $account,
            'amount' => $tiyin,
            'redirectUrl' => $returnUrl,
        ], fn ($v) => $v !== null && $v !== ''));
    }
}
