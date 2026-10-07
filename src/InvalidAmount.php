<?php

namespace Uzpay;

use InvalidArgumentException;

/**
 * Money got something that is not a sum ("12,500", "abc").
 */
final class InvalidAmount extends InvalidArgumentException implements UzpayException {}
