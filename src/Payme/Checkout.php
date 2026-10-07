<?php

namespace Uzpay\Payme;

/**
 * Link to the Payme payment page: base64 of "m=<merchant>;ac.<account key>=<value>;a=<tiyin>;c=<return url>".
 * The values are not escaped: the account and the return URL must not contain ";".
 */
final class Checkout
{
    public const LIVE = 'https://checkout.paycom.uz';

    public const TEST = 'https://test.paycom.uz';

    public static function url(string $merchantId, string $accountKey, string $account, int $tiyin, string $returnUrl, bool $test = false): string
    {
        $params = sprintf('m=%s;ac.%s=%s;a=%d;c=%s', $merchantId, $accountKey, $account, $tiyin, $returnUrl);

        return ($test ? self::TEST : self::LIVE).'/'.base64_encode($params);
    }
}
