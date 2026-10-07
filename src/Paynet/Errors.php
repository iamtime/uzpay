<?php

namespace Uzpay\Paynet;

/**
 * Paynet provider API error codes with the messages the payer sees.
 */
final class Errors
{
    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const SYSTEM_ERROR = 102;

    public const TRANSACTION_EXISTS = 201;

    public const TRANSACTION_CANCELED = 202;

    public const TRANSACTION_NOT_FOUND = 203;

    public const CLIENT_NOT_FOUND = 302;

    public const SERVICE_NOT_FOUND = 305;

    public const MISSING_PARAMETERS = 411;

    public const INVALID_LOGIN = 412;

    public const INVALID_AMOUNT = 413;

    public const INVALID_DATE = 414;

    /** The payment can not be canceled any more (the order is completed). */
    public const CANNOT_CANCEL = 501;

    public const ACCESS_DENIED = 601;

    public const MESSAGES = [
        self::INVALID_REQUEST => 'Неверный запрос',
        self::METHOD_NOT_FOUND => 'Метод не найден',
        self::SYSTEM_ERROR => 'Системная ошибка',
        self::TRANSACTION_EXISTS => 'Транзакция уже существует',
        self::TRANSACTION_CANCELED => 'Транзакция уже отменена',
        self::TRANSACTION_NOT_FOUND => 'Транзакция не найдена',
        self::CLIENT_NOT_FOUND => 'Заказ не найден или уже оплачен',
        self::SERVICE_NOT_FOUND => 'Услуга не найдена',
        self::MISSING_PARAMETERS => 'Не заданы обязательные параметры',
        self::INVALID_LOGIN => 'Неверный логин или пароль',
        self::INVALID_AMOUNT => 'Неверная сумма',
        self::INVALID_DATE => 'Неверный формат даты',
        self::CANNOT_CANCEL => 'Заказ выполнен, отмена невозможна',
        self::ACCESS_DENIED => 'Доступ запрещён',
    ];

    /** @return array<string, mixed> */
    public static function response(mixed $id, int $code): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => self::MESSAGES[$code] ?? self::MESSAGES[self::SYSTEM_ERROR]]];
    }

    /** @return array<string, mixed> */
    public static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
