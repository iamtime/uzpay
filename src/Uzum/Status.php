<?php

namespace Uzpay\Uzum;

/**
 * Transaction statuses in Uzum's answers.
 */
final class Status
{
    public const OK = 'OK';

    public const CREATED = 'CREATED';

    public const CONFIRMED = 'CONFIRMED';

    public const REVERSED = 'REVERSED';

    public const FAILED = 'FAILED';
}
