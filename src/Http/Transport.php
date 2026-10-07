<?php

namespace Uzpay\Http;

/**
 * How the package calls a provider's API (Octo, Payme Subscribe API). CurlTransport works out of the box; an app may
 * pass its own (a framework HTTP client, a fake in tests).
 *
 * Contract for an implementation:
 *  - send the body as JSON with the extra $headers (Payme authorizes with X-Auth);
 *  - return the decoded JSON for any HTTP status: Payme and Octo put their errors in the body;
 *  - throw TransportException only when there is no answer or it is not JSON;
 *  - never log the headers or the body: they carry the merchant's keys.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    public function post(string $url, array $body, array $headers = []): array;
}
