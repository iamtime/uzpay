<?php

namespace Uzpay\Http;

use JsonException;

/**
 * The default Transport: a JSON POST over ext-curl. $timeout is in seconds; there are no retries - a payment call
 * must not be repeated blindly. Extra headers are sent after Content-Type and Accept.
 */
final class CurlTransport implements Transport
{
    public function __construct(private int $timeout = 30) {}

    public function post(string $url, array $body, array $headers = []): array
    {
        if (! function_exists('curl_init')) {
            throw new TransportException('ext-curl is not installed: pass another Uzpay\Http\Transport');
        }
        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException("{$url}: the request is not JSON-encodable: {$e->getMessage()}", previous: $e);
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', ...array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($headers), $headers)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (! is_string($response)) {
            throw new TransportException("{$url}: {$error}");
        }
        $answer = json_decode($response, true);
        if (! is_array($answer)) {
            throw new TransportException("{$url}: HTTP {$status}, not JSON: ".substr($response, 0, 200));
        }

        return $answer;
    }
}
