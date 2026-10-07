<?php

namespace Uzpay\Octo;

/**
 * Payment statuses in Octo's answers and notifications.
 */
final class Status
{
    public const CREATED = 'created';

    public const WAIT_USER_ACTION = 'wait_user_action';

    /** Two-stage payment: the money is held, Octo waits for our capture or cancel. */
    public const WAITING_FOR_CAPTURE = 'waiting_for_capture';

    public const SUCCEEDED = 'succeeded';

    public const CANCELED = 'canceled';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';
}
