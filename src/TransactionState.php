<?php

namespace Uzpay;

/**
 * Transaction states as Payme numbers them; the other providers use the same ones.
 */
final class TransactionState
{
    public const CREATED = 1;

    public const PERFORMED = 2;

    public const CANCELED = -1;

    public const CANCELED_AFTER_PERFORM = -2;
}
