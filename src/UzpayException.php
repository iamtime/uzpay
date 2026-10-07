<?php

namespace Uzpay;

use Throwable;

/**
 * Every error the package throws on purpose: catch this one to handle them all
 * (Http\TransportException, Octo\OctoException, Payme\SubscribeException, InvalidAmount).
 */
interface UzpayException extends Throwable {}
