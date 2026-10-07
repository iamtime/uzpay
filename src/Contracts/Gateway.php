<?php

namespace Uzpay\Contracts;

/**
 * A merchant's keys at one provider.
 *
 * Payme (Merchant API and Subscribe API): merchant_id, key, test_key. Click: service_id, merchant_id, secret_key.
 * Uzum: service_id, login, password. Paynet: service_id (optional), login, password. Octo: shop_id, secret,
 * unique_key.
 *
 * testMode(): Payme checks test_key and the Subscribe API goes to checkout.test.paycom.uz (both fall back to key
 * when there is no test_key - keep a test_key so the live key never travels to the test host); Octo sends
 * "test": true. Click, Uzum and Paynet have no test mode here: they test with separate test credentials.
 */
interface Gateway
{
    public function credential(string $key): ?string;

    public function testMode(): bool;
}
