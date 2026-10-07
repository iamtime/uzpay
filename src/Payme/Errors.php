<?php

namespace Uzpay\Payme;

/**
 * Payme Merchant API error codes with the messages in three languages that Payme shows to the payer.
 */
final class Errors
{
    public const INSUFFICIENT_PRIVILEGE = -32504;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const WRONG_AMOUNT = -31001;

    public const TRANSACTION_NOT_FOUND = -31003;

    public const CANNOT_CANCEL = -31007;

    public const CANNOT_PERFORM = -31008;

    public const ORDER_NOT_FOUND = -31050;

    public const ORDER_NOT_PAYABLE = -31051;

    public const ORDER_BUSY = -31052;

    public const MESSAGES = [
        self::INSUFFICIENT_PRIVILEGE => ['ru' => 'Недостаточно привилегий', 'uz' => 'Ruxsat yo\'q', 'en' => 'Insufficient privilege'],
        self::INVALID_REQUEST => ['ru' => 'Неверный запрос', 'uz' => 'Noto\'g\'ri so\'rov', 'en' => 'Invalid request'],
        self::METHOD_NOT_FOUND => ['ru' => 'Метод не найден', 'uz' => 'Metod topilmadi', 'en' => 'Method not found'],
        self::WRONG_AMOUNT => ['ru' => 'Неверная сумма', 'uz' => 'Noto\'g\'ri summa', 'en' => 'Incorrect amount'],
        self::TRANSACTION_NOT_FOUND => ['ru' => 'Транзакция не найдена', 'uz' => 'Tranzaksiya topilmadi', 'en' => 'Transaction not found'],
        self::CANNOT_CANCEL => ['ru' => 'Заказ выполнен, отмена невозможна', 'uz' => 'Buyurtma bajarilgan', 'en' => 'Order completed, cannot cancel'],
        self::CANNOT_PERFORM => ['ru' => 'Невозможно выполнить операцию', 'uz' => 'Amalni bajarib bo\'lmaydi', 'en' => 'Unable to perform operation'],
        self::ORDER_NOT_FOUND => ['ru' => 'Заказ не найден', 'uz' => 'Buyurtma topilmadi', 'en' => 'Order not found'],
        self::ORDER_NOT_PAYABLE => ['ru' => 'Заказ уже оплачен или отменён', 'uz' => 'Buyurtma to\'langan yoki bekor qilingan', 'en' => 'Order already paid or canceled'],
        self::ORDER_BUSY => ['ru' => 'По заказу уже идёт оплата', 'uz' => 'Buyurtma to\'lanmoqda', 'en' => 'Order payment in progress'],
    ];

    /** @return array<string, mixed> */
    public static function response(mixed $id, int $code, ?string $data = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => array_filter([
            'code' => $code,
            'message' => self::MESSAGES[$code] ?? self::MESSAGES[self::INVALID_REQUEST],
            'data' => $data,
        ], fn ($v) => $v !== null)];
    }

    /** @return array<string, mixed> */
    public static function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
