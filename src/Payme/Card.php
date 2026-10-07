<?php

namespace Uzpay\Payme;

/**
 * A card saved in Payme (Subscribe API): the merchant keeps only the token and the masked number, never the card.
 */
final class Card
{
    public function __construct(
        public readonly string $token,
        public readonly string $number,
        public readonly string $expire,
        /** The token may pay again and again (save: true), not just once. */
        public readonly bool $recurrent,
        /** The owner confirmed the card with the SMS code. Payme pays only from a verified card. */
        public readonly bool $verified,
    ) {}

    /** @param  array<string, mixed>  $card  "card" of cards.create, cards.verify, cards.check */
    public static function fromArray(array $card): self
    {
        return new self(
            (string) ($card['token'] ?? ''),
            (string) ($card['number'] ?? ''),
            (string) ($card['expire'] ?? ''),
            (bool) ($card['recurrent'] ?? false),
            (bool) ($card['verify'] ?? false),
        );
    }
}
