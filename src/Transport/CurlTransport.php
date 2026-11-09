<?php

declare(strict_types=1);

namespace Trailscope\Transport;

use CurlHandle;
use Throwable;

/**
 * curl transport. The handle is kept, so long-running processes (queue
 * workers, Octane) reuse the connection.
 */
final class CurlTransport implements Transport
{
    private ?CurlHandle $handle = null;

    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        if ($url === '' || $method === '') {
            return new Response(0, error: 'invalid request');
        }
        try {
            $h = $this->handle ??= curl_init();
            if ($h === false) {
                return new Response(0, error: 'curl_init failed');
            }
            curl_reset($h);
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }
            $lines[] = 'Expect:';
            $retryAfter = null;
            curl_setopt_array($h, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $_, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'retry-after:') === 0) {
                        $retryAfter = trim(substr($line, 12));
                    }

                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($h, CURLOPT_POSTFIELDS, $body);
            }
            $result = curl_exec($h);
            if (! is_string($result)) {
                $error = curl_error($h);
                // Start over with a fresh handle after a network error.
                $this->handle = null;

                return new Response(0, error: $error !== '' ? $error : 'request failed');
            }

            return new Response((int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $result, $retryAfter);
        } catch (Throwable $e) {
            $this->handle = null;

            return new Response(0, error: $e->getMessage());
        }
    }
}
