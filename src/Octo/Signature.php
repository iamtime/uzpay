<?php

namespace Uzpay\Octo;

/**
 * Octo notification signature: upper-case sha1(unique_key + octo_payment_UUID + status). The unique key is
 * not the API secret: Octo's support gives it.
 */
final class Signature
{
    public static function make(string $uuid, string $status, string $uniqueKey): string
    {
        return strtoupper(sha1($uniqueKey.$uuid.$status));
    }

    /** @param array<string, mixed> $body */
    public static function valid(array $body, ?string $uniqueKey): bool
    {
        return $uniqueKey !== null && $uniqueKey !== ''
            && hash_equals(self::make((string) ($body['octo_payment_UUID'] ?? ''), (string) ($body['status'] ?? ''), $uniqueKey), strtoupper((string) ($body['signature'] ?? '')));
    }
}
