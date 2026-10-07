<?php

namespace Uzpay\Fiscal;

use Uzpay\Money;

/**
 * Fiscal receipt lines: Payme's "detail" (CheckPerformTransaction, Subscribe receipts) and the source of Octo\Basket.
 * Each line carries the MXIK (IKPU) code, the package code and the VAT rate. Sums are given in so'm, kept in tiyin.
 *
 *   Receipt::make()->item('Чехол', '50000', 2, '06109001001000000', '1503426', 12)->discount('Скидка', '5000')->toArray()
 */
final class Receipt
{
    public const TITLE_MAX = 128;

    /** @var list<array<string, mixed>> */
    private array $items = [];

    private ?array $shipping = null;

    private ?array $discount = null;

    public function __construct(private int $type = 0) {}

    /** @param int $type 0 - sale, 1 - prepayment. */
    public static function make(int $type = 0): self
    {
        return new self($type);
    }

    public function item(string $title, string|int|float $price, int|float $count, ?string $code, ?string $packageCode, int $vatPercent = 0): self
    {
        $this->items[] = [
            'title' => mb_substr($title, 0, self::TITLE_MAX),
            'price' => Money::tiyin($price),
            'count' => $count,
            'code' => $code,
            'package_code' => $packageCode,
            'vat_percent' => $vatPercent,
        ];

        return $this;
    }

    /** Skipped when the sum is zero. */
    public function shipping(string $title, string|int|float $price): self
    {
        $tiyin = Money::tiyin($price);
        $this->shipping = $tiyin > 0 ? ['title' => mb_substr($title, 0, self::TITLE_MAX), 'price' => $tiyin] : null;

        return $this;
    }

    /** Points, gift cards and promo codes all go here: for the receipt they are a discount. Skipped when zero. */
    public function discount(string $title, string|int|float $price): self
    {
        $tiyin = Money::tiyin($price);
        $this->discount = $tiyin > 0 ? ['title' => mb_substr($title, 0, self::TITLE_MAX), 'price' => $tiyin] : null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'receipt_type' => $this->type,
            'items' => $this->items,
            'shipping' => $this->shipping,
            'discount' => $this->discount,
        ], fn ($v) => $v !== null);
    }

    /**
     * What the tax office will not accept: "items.0.code" and the like. Empty when the receipt is fine.
     *
     * @param  list<int>  $vatRates  rates in force (12% since 2023, 0 for exempt goods)
     * @return list<string>
     */
    public function problems(array $vatRates = [0, 12]): array
    {
        $problems = [];
        foreach ($this->items as $i => $item) {
            if (trim($item['title']) === '') {
                $problems[] = "items.{$i}.title";
            }
            if (! preg_match('/^\d{17}$/', (string) $item['code'])) {
                $problems[] = "items.{$i}.code";
            }
            if (trim((string) $item['package_code']) === '') {
                $problems[] = "items.{$i}.package_code";
            }
            if (! in_array($item['vat_percent'], $vatRates, true)) {
                $problems[] = "items.{$i}.vat_percent";
            }
            if ($item['price'] < 0 || $item['count'] <= 0) {
                $problems[] = "items.{$i}.price";
            }
        }

        return $this->items === [] ? ['items'] : $problems;
    }
}
