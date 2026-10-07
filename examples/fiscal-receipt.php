<?php

/*
| php examples/fiscal-receipt.php
|
| The fiscal receipt Payme asks for in CheckPerformTransaction ("detail"). Every line needs the MXIK (IKPU) code
| of the goods, the package code and the VAT rate; points, gift cards and promo codes go as one discount line,
| so the receipt adds up to the sum paid.
*/

require __DIR__.'/bootstrap.php';

use Uzpay\Fiscal\Receipt;

$receipt = Receipt::make()
    ->item('Чехол для телефона Samsung A54', '45000', 2, '08517001001000000', '1503426', 12)
    ->item('Защитное стекло', '30000', 1, '07007001002000000', '1503426', 12)
    ->shipping('Доставка', '20000')
    ->discount('Скидка и баллы', '15000');

echo json_encode($receipt->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n\n";
echo 'Проблемы: ', implode(', ', $receipt->problems()) ?: 'нет', "\n";

// A line without the MXIK code and with an unknown VAT rate: the tax office will not accept it.
$bad = Receipt::make()->item('Носки', '12000', 3, null, '', 20);
echo 'Проблемы неполного чека: ', implode(', ', $bad->problems()), "\n";
