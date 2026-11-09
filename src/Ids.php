<?php

declare(strict_types=1);

namespace Trailscope;

use Throwable;

/**
 * Request, trace and span IDs, and W3C `traceparent`.
 */
final class Ids
{
    private const TRACEPARENT = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    private const REQUEST_ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** 32 hex characters, like the server's generated IDs. */
    public static function requestId(): string
    {
        return self::hex(16);
    }

    public static function traceId(): string
    {
        return self::hex(16);
    }

    public static function spanId(): string
    {
        return self::hex(8);
    }

    /**
     * Parses a W3C `traceparent` header; null when it is missing or invalid.
     *
     * @return array{traceId: string, parentSpanId: string, sampled: bool}|null
     */
    public static function parseTraceparent(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (preg_match(self::TRACEPARENT, strtolower(trim($header)), $m) !== 1) {
            return null;
        }
        [, $version, $traceId, $spanId, $flags] = $m;
        if ($version === 'ff' || trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return null;
        }

        return ['traceId' => $traceId, 'parentSpanId' => $spanId, 'sampled' => (hexdec($flags) & 1) === 1];
    }

    public static function formatTraceparent(string $traceId, string $spanId, bool $sampled = true): string
    {
        return '00-'.$traceId.'-'.$spanId.'-'.($sampled ? '01' : '00');
    }

    /** A client-provided request ID, only if it looks sane. */
    public static function acceptRequestId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);

        return preg_match(self::REQUEST_ID, $v) === 1 ? $v : null;
    }

    /** @param int<1, max> $bytes */
    private static function hex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable) {
            // No CSPRNG available: IDs only need to be unique, not secret.
            $out = '';
            while (strlen($out) < $bytes * 2) {
                $out .= sprintf('%08x', mt_rand(0, 0x7FFFFFFF));
            }

            return substr($out, 0, $bytes * 2);
        }
    }
}
