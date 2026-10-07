<?php

namespace Uzpay\Http;

use RuntimeException;
use Uzpay\UzpayException;

/**
 * The provider could not be reached or did not answer with JSON: the outcome of the call is unknown.
 * The message carries the URL and the start of the answer - never the request body or headers.
 */
final class TransportException extends RuntimeException implements UzpayException {}
