<?php

namespace Uzpay\Paynet;

/**
 * transactionState in CheckTransaction and CancelTransaction answers.
 */
final class TransactionState
{
    public const SUCCESS = 1;

    public const CANCELED = 2;

    public const NOT_FOUND = 3;
}
