<?php

namespace Uzpay\Uzum;

/**
 * Uzum Merchant API error codes, sent as "errorCode" with status FAILED.
 */
final class Errors
{
    public const ACCESS_DENIED = 10001;

    public const JSON_PARSE = 10002;

    public const INVALID_OPERATION = 10003;

    public const MISSING_PARAMS = 10005;

    public const INVALID_SERVICE = 10006;

    public const ACCOUNT_NOT_FOUND = 10007;

    public const ALREADY_PAID = 10008;

    public const PAYMENT_CANCELED = 10009;

    public const TRANSACTION_EXISTS = 10010;

    public const INVALID_AMOUNT = 10011;

    public const TRANSACTION_NOT_FOUND = 10014;

    public const TRANSACTION_CANCELED = 10015;

    public const CANNOT_REVERSE = 10017;

    public const ALREADY_REVERSED = 10018;

    public const UNAVAILABLE = 99999;
}
