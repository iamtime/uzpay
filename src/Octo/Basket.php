<?php

namespace Uzpay\Octo;

use Uzpay\Money;

/**
 * Octo's "basket" for the fiscal receipt, built from Fiscal\Receipt::toArray(). Octo fiscalises only the
 * goods its manager has registered for the shop (MXIK, package code, VAT), and position names may hold
 * Latin letters, digits and - . _ ( ) only, so names are transliterated.
 *
 * Octo has no discount or shipping lines: a receipt with them gives null, and Octo fiscalises the payment
 * by the shop's default goods.
 */
final class Basket
{
    private const LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'j', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh',
        'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
        'ʻ' => '', 'ʼ' => '', '‘' => '', '’' => '', "'" => '', '`' => '',
    ];

    /**
     * @param  array<string, mixed>  $receipt  Fiscal\Receipt::toArray()
     * @param  string  $tin  the shop's INN, or PINFL for an individual entrepreneur
     * @return list<array<string, mixed>>|null
     */
    public static function fromReceipt(array $receipt, string $tin, bool $individual = false): ?array
    {
        if (isset($receipt['shipping']) || isset($receipt['discount']) || empty($receipt['items']) || trim($tin) === '') {
            return null;
        }

        $basket = [];
        foreach ($receipt['items'] as $item) {
            if (empty($item['code']) || empty($item['package_code'])) {
                return null;
            }
            $basket[] = [
                'position_desc' => self::latin((string) $item['title']),
                'count' => $item['count'],
                'price' => (float) Money::soum((int) $item['price']),
                'spic' => (string) $item['code'],
                $individual ? 'pinfl' : 'inn' => trim($tin),
                'package_code' => (string) $item['package_code'],
                'nds' => (int) ($item['vat_percent'] ?? 0) > 0 ? 1 : 0,
            ];
        }

        return $basket;
    }

    /** "Чехол «Люкс», 2 шт." → "Chexol Lyuks 2 sht." */
    public static function latin(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $lower = mb_strtolower($char);
            $latin = self::LATIN[$lower] ?? $char;
            $out .= $lower !== $char && $latin !== '' ? ucfirst($latin) : $latin;
        }
        $out = preg_replace('/[^A-Za-z0-9 \-._()]+/', ' ', $out) ?? '';

        return trim(preg_replace('/\s+/', ' ', $out) ?? '') ?: 'Tovar';
    }
}
