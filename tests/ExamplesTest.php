<?php

/*
| The examples stay working: each runnable one is started as a separate PHP process.
*/

function uzpayExample(string $file): string
{
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/examples/'.$file).' 2>&1', $output, $code);
    expect($code)->toBe(0, implode("\n", $output));

    return implode("\n", $output);
}

it('builds the example receipt without problems', function () {
    expect(uzpayExample('fiscal-receipt.php'))->toContain('"vat_percent": 12')->toContain('Проблемы: нет')
        ->toContain('items.0.code, items.0.package_code, items.0.vat_percent');
});

it('prints checkout links', function () {
    expect(uzpayExample('checkout-links.php'))->toContain('https://checkout.paycom.uz/')->toContain('https://my.click.uz/services/pay?')
        ->toContain('https://www.uzumbank.uz/open-service?serviceId=')->toContain('https://app.paynet.uz/?m=');
});

it('pays the sandbox orders in memory', function () {
    expect(uzpayExample('sandbox.php'))->toContain('Заказ 1001: paid через payme')->toContain('Заказ 1002: paid через click')
        ->toContain('Заказ 1003: paid через uzum')->toContain('Заказ 1004: paid через paynet')->toContain('Заказ 1005: paid через octo');
});

it('saves a card and charges monthly invoices in the Payme Subscribe example', function () {
    expect(uzpayExample('payme-subscribe.php'))->toContain('Неверный код')->toContain('Карта 860006******6311 сохранена, подтверждена: да')
        ->toContain('Счёт 2001: чек')->toContain('Чек оплачен, счёт paid')->toContain('Карта отвязана: да');
});

it('pays and refunds orders in the PDO example', function () {
    expect(uzpayExample('pdo/demo.php'))->toContain('Заказ 1001 на 125000.00 сум: refunded (payme)')->toContain('Заказ 1002 на 48000.00 сум: paid (click)')
        ->toContain('Заказ 1003 на 73500.00 сум: paid (uzum)')->toContain('Заказ 1004 на 19900.00 сум: paid (paynet)')->toContain('Заказ 1005 на 250000.00 сум: paid (octo)');
})->skip(! extension_loaded('pdo_sqlite'), 'pdo_sqlite is not installed');
