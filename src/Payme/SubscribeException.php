<?php

namespace Uzpay\Payme;

use RuntimeException;
use Uzpay\UzpayException;

/**
 * Payme refused a Subscribe API call: the JSON-RPC error code, its message (Russian when Payme gives several
 * languages) and its data as Payme sent them.
 */
final class SubscribeException extends RuntimeException implements UzpayException
{
    /** @param  array<string, string>  $messages  ru / uz / en when Payme gives them */
    public function __construct(string $message, int $code, public readonly mixed $data = null, public readonly array $messages = [])
    {
        parent::__construct($message, $code);
    }

    /** @param  array<string, mixed>  $error  "error" of a JSON-RPC answer */
    public static function fromError(array $error): self
    {
        $message = $error['message'] ?? 'Payme error';
        $messages = is_array($message) ? array_map('strval', $message) : [];
        $text = is_array($message) ? ($message['ru'] ?? $message['en'] ?? reset($message) ?: 'Payme error') : (string) $message;

        return new self((string) $text, (int) ($error['code'] ?? -1), $error['data'] ?? null, $messages);
    }
}
