<?php

namespace Uzpay\Click;

use Uzpay\Money;

/**
 * Link to the Click payment page. transaction_param comes back as merchant_trans_id in Prepare / Complete.
 */
final class Checkout
{
    public const URL = 'https://my.click.uz/services/pay';

    public static function url(string $serviceId, string $merchantId, string $amount, string $transactionParam, string $returnUrl): string
    {
        return self::URL.'?'.http_build_query([
            'service_id' => $serviceId,
            'merchant_id' => $merchantId,
            'amount' => Money::soum(Money::tiyin($amount)),
            'transaction_param' => $transactionParam,
            'return_url' => $returnUrl,
        ]);
    }
}
