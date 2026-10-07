<?php

namespace App\Payments;

use Uzpay\Contracts\Gateway;

/**
 * Merchant keys from config/services.php.
 */
final class ConfigGateway implements Gateway
{
    public function __construct(private string $provider) {}

    public function credential(string $key): ?string
    {
        $value = config("services.{$this->provider}.{$key}");

        return filled($value) ? (string) $value : null;
    }

    public function testMode(): bool
    {
        return (bool) config("services.{$this->provider}.test");
    }
}
