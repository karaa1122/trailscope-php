<?php

declare(strict_types=1);

namespace Trailscope\Transport;

use Throwable;

/**
 * Transport on PHP's HTTP stream wrapper, for installations without curl.
 * Streams have a single timeout for connecting and reading.
 */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        try {
            $lines = '';
            foreach ($headers as $name => $value) {
                $lines .= $name.': '.$value."\r\n";
            }
            $lines .= "Connection: close\r\n";
            $http = [
                'method' => $method,
                'header' => $lines,
                'timeout' => max(0.001, $timeout),
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ];
            if ($body !== null) {
                $http['content'] = $body;
            }
            $context = stream_context_create(['http' => $http]);
            $error = null;
            set_error_handler(static function (int $_, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });
            $result = false;
            $responseHeaders = [];
            try {
                $fp = fopen($url, 'r', false, $context);
                if ($fp !== false) {
                    $meta = stream_get_meta_data($fp);
                    $responseHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
                    $result = stream_get_contents($fp);
                    fclose($fp);
                }
            } finally {
                restore_error_handler();
            }
            if ($result === false || $responseHeaders === []) {
                return new Response(0, error: $error ?? 'request failed');
            }
            $status = 0;
            $retryAfter = null;
            foreach ($responseHeaders as $line) {
                if (! is_string($line)) {
                    continue;
                }
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    // The last status line wins (after a 100 Continue).
                    $status = (int) $m[1];
                } elseif (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }
            }

            return new Response($status, $result, $retryAfter, $status === 0 ? 'no status line' : null);
        } catch (Throwable $e) {
            return new Response(0, error: $e->getMessage());
        }
    }
}
