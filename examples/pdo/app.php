<?php

/*
| Wiring of the PDO example: the database, the merchant keys, one handler per provider.
| webhook.php serves them over HTTP; demo.php calls them through the emulator.
*/

require __DIR__.'/../bootstrap.php';
require __DIR__.'/Order.php';
require __DIR__.'/Orders.php';
require __DIR__.'/PdoTransactions.php';

use Uzpay\Click\Shop;
use Uzpay\Contracts\Gateway;
use Uzpay\Octo\Client as OctoClient;
use Uzpay\Octo\Notifications as OctoNotifications;
use Uzpay\Octo\Payments as OctoPayments;
use Uzpay\Payme\Merchant;
use Uzpay\Paynet\Merchant as PaynetMerchant;
use Uzpay\Testing\FakeOcto;
use Uzpay\Uzum\Merchant as UzumMerchant;

/** Merchant keys. Keep them out of the code: environment, encrypted settings... */
final class Keys implements Gateway
{
    /** @param array<string, string> $keys */
    public function __construct(private array $keys, private bool $test) {}

    public function credential(string $key): ?string
    {
        $value = $this->keys[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function testMode(): bool
    {
        return $this->test;
    }
}

function shop_database(string $file = __DIR__.'/shop.sqlite'): PDO
{
    $db = new PDO('sqlite:'.$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents(__DIR__.'/schema.sql'));

    return $db;
}

/**
 * The handlers with the keys from the environment (PAYME_MERCHANT_ID, PAYME_KEY, PAYME_TEST_KEY, CLICK_*, UZUM_*,
 * PAYNET_*, OCTO_*). $demo - made-up keys for demo.php only: a missing key then falls back to a public demo value
 * and the provider runs in test mode. Without $demo a missing key is an error, so a deployed webhook can never
 * accept calls signed with the demo keys anyone can read here.
 *
 * @return array{payme: Closure, click: Closure, uzum: Closure, paynet: Closure, octo: Closure, octo_payments: Closure}
 */
function payment_handlers(PDO $db, bool $demo = false): array
{
    $orders = new Orders($db);
    $store = new PdoTransactions($db, $orders);
    $env = function (string $name, string $demoValue) use ($demo): string {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (! $demo) {
            throw new RuntimeException("Set {$name}: outside demo.php the example never uses demo keys.");
        }

        return $demoValue;
    };
    // Keys are read when a provider's handler runs: a shop that uses only Payme sets only PAYME_*.
    $payme = fn () => new Keys(['merchant_id' => $env('PAYME_MERCHANT_ID', 'demo'), 'key' => $demo ? (string) getenv('PAYME_KEY') : $env('PAYME_KEY', ''),
        'test_key' => $demo ? $env('PAYME_TEST_KEY', 'payme-test-key') : (getenv('PAYME_TEST_KEY') ?: null)], test: $demo || getenv('PAYME_TEST') === '1');
    $click = fn () => new Keys(['service_id' => $env('CLICK_SERVICE_ID', '100'), 'merchant_id' => $env('CLICK_MERCHANT_ID', '200'),
        'secret_key' => $env('CLICK_SECRET_KEY', 'click-secret')], test: $demo);
    $uzum = fn () => new Keys(['service_id' => $env('UZUM_SERVICE_ID', '300'), 'login' => $env('UZUM_LOGIN', 'uzum'),
        'password' => $env('UZUM_PASSWORD', 'uzum-pass')], test: $demo);
    $paynet = fn () => new Keys(['service_id' => $env('PAYNET_SERVICE_ID', '400'), 'login' => $env('PAYNET_LOGIN', 'paynet'),
        'password' => $env('PAYNET_PASSWORD', 'paynet-pass')], test: $demo);
    $octo = fn () => new Keys(['shop_id' => $env('OCTO_SHOP_ID', '500'), 'secret' => $env('OCTO_SECRET', 'octo-secret'),
        'unique_key' => $env('OCTO_UNIQUE_KEY', 'octo-unique')], test: $demo);

    return [
        'payme' => fn (array $body, ?string $authorization) => (new Merchant($store, $orders))->handle($body, $authorization, $payme()),
        'click' => fn (array $params) => (new Shop($store, $orders))->handle($params, $click()),
        'uzum' => fn (string $action, array $body, ?string $authorization) => (new UzumMerchant($store, $orders))->handle($action, $body, $authorization, $uzum()),
        // Paynet may change its password: keep the new one where the keys live (here: nowhere, so it is refused).
        'paynet' => fn (array $body, ?string $authorization) => (new PaynetMerchant($store, $orders))->handle($body, $authorization, $paynet()),
        'octo' => fn (array $body) => (new OctoNotifications($store))->handle($body, $octo()),
        // In the demo Octo's API is played by FakeOcto: no request leaves the machine.
        'octo_payments' => fn () => new OctoPayments($store, new OctoClient($octo(), $demo ? new FakeOcto : null)),
    ];
}
