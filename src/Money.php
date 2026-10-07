<?php

namespace Uzpay;

/**
 * Sums in so'm as decimal strings ("12500.50") and in tiyin as integers (1250050). Payme, Uzum and Paynet count in
 * tiyin, Click and Octo in so'm. Strings are converted digit by digit, without float rounding; a third decimal is
 * rounded half up ("0.005" → 1 tiyin). Anything else throws InvalidAmount.
 */
final class Money
{
    public static function tiyin(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            return (int) round($amount * 100);
        }
        if (! preg_match('/^\s*(-?)(\d*)(?:\.(\d*))?\s*$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidAmount("Not an amount: {$amount}");
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $tiyin = (int) $m[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$tiyin : $tiyin;
    }

    /** 1250050 → "12500.50" */
    public static function soum(int $tiyin): string
    {
        $sign = $tiyin < 0 ? '-' : '';
        $tiyin = abs($tiyin);

        return $sign.intdiv($tiyin, 100).'.'.str_pad((string) ($tiyin % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function same(string|int|float $a, string|int|float $b): bool
    {
        return self::tiyin($a) === self::tiyin($b);
    }
}
