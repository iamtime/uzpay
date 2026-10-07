<?php

namespace Uzpay\Click;

/**
 * Click SHOP API signature: md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id
 * [+ merchant_prepare_id on Complete] + amount + action + sign_time).
 */
final class Signature
{
    /** @param array<string, mixed> $params */
    public static function make(array $params, string $secret): string
    {
        return md5(($params['click_trans_id'] ?? '').($params['service_id'] ?? '').$secret
            .($params['merchant_trans_id'] ?? '')
            .((int) ($params['action'] ?? -1) === Shop::COMPLETE ? ($params['merchant_prepare_id'] ?? '') : '')
            .($params['amount'] ?? '').($params['action'] ?? '').($params['sign_time'] ?? ''));
    }

    /** @param array<string, mixed> $params */
    public static function valid(array $params, ?string $secret): bool
    {
        return $secret !== null && $secret !== ''
            && hash_equals(self::make($params, $secret), (string) ($params['sign_string'] ?? ''));
    }
}
