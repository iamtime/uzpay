<?php

namespace Uzpay;

enum PayableState: string
{
    /** Waiting for payment. */
    case Open = 'open';

    case Paid = 'paid';

    /** Canceled or otherwise not payable any more. */
    case Closed = 'closed';
}
