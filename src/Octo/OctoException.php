<?php

namespace Uzpay\Octo;

use RuntimeException;
use Uzpay\UzpayException;

/**
 * Octo refused a request (error code from its answer) or the payable can't be paid through it.
 */
final class OctoException extends RuntimeException implements UzpayException {}
