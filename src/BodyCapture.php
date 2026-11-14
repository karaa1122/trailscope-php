<?php

declare(strict_types=1);

namespace Trailscope;

use JsonSerializable;
use Stringable;

/**
 * Body capture policy, serialization and truncation, and header helpers.
 */
final class BodyCapture
{
    public const ERRORS = 'errors';

    public const ALWAYS = 'always';

    public const NEVER = 'never';

    private const BINARY_TYPES = [
        'multipart/', 'application/octet-stream', 'image/', 'audio/', 'video/', 'font/',
        'application/pdf', 'application/zip', 'application/gzip', 'application/x-protobuf', 'application/grpc',
    ];

    /**
     * Compressed bodies are inflated this many bytes at a time, so a small
     * but highly compressed body (deflate reaches about 1000:1) can't expand
     * far past the limit before decoding stops.
     */
    private const INFLATE_CHUNK_BYTES = 1024;

    /** Whether a body with this content type should never be captured. */
    public static function isBinaryContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }
        $ct = strtolower($contentType);
        foreach (self::BINARY_TYPES as $prefix) {
            if (str_starts_with($ct, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** `errors` keeps bodies of responses with status >= 400 or slow ones. */
    public static function shouldCapture(string $policy, int $status, float $durationMs, float $slowMs): bool
    {
        return match ($policy) {
            self::NEVER => false,
            self::ALWAYS => true,
            default => $status >= 400 || $durationMs >= $slowMs,
        };
    }

    /**
     * Undoes a response's `Content-Encoding` so its body can be captured.
     * Without an encoding (or with `identity`) the body is returned as it is.
     * A `gzip` or `deflate` body is decoded until more than `$limit` bytes
     * are out, so `truncate()` still sees whether it was cut. Returns null
     * when the body can't be read: another encoding (`br`, `zstd`, several
     * encodings), a value that isn't a string, corrupt data, or no ext-zlib.
     */
    public static function decode(mixed $body, ?string $encoding, int $limit): mixed
    {
        $encoding = strtolower(trim($encoding ?? ''));
        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }
        if (! is_string($body) || $body === '' || ! function_exists('inflate_init')) {
            return null;
        }
        $mode = match ($encoding) {
            'gzip', 'x-gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            default => null,
        };
        if ($mode === null) {
            return null;
        }
        $inflate = @inflate_init($mode);
        if ($inflate === false) {
            return null;
        }
        $out = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length && strlen($out) <= $limit; $offset += self::INFLATE_CHUNK_BYTES) {
            $chunk = @inflate_add($inflate, substr($body, $offset, self::INFLATE_CHUNK_BYTES), ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                return null;
            }
            $out .= $chunk;
            if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $out === '' ? null : substr($out, 0, $limit + 1);
    }

    /**
     * Cuts `$text` to at most `$limit` bytes at a UTF-8 character boundary.
     *
     * @return array{0: string, 1: bool} the text and whether it was cut
     */
    public static function truncate(string $text, int $limit): array
    {
        if (strlen($text) <= $limit) {
            return [$text, false];
        }
        $cut = substr($text, 0, max(0, $limit));
        // Drop a trailing partial multi-byte character.
        $i = strlen($cut) - 1;
        $continuation = 0;
        while ($i >= 0 && $continuation < 3 && (ord($cut[$i]) & 0xC0) === 0x80) {
            $i--;
            $continuation++;
        }
        if ($i >= 0) {
            $lead = ord($cut[$i]);
            $need = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : ($lead >= 0xC0 ? 2 : 1));
            if (strlen($cut) - $i < $need) {
                $cut = substr($cut, 0, $i);
            }
        }

        return [$cut, true];
    }

    /**
     * Serializes a body-like value and truncates it to `$limit` bytes. Returns
     * null for binary or empty bodies. Raw bodies of binary and multipart
     * requests are skipped; already parsed form fields (an array) are kept.
     *
     * @return array{0: string, 1: bool}|null the text and whether it was cut
     */
    public static function serialize(mixed $value, ?string $contentType, int $limit): ?array
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Stringable && ! $value instanceof JsonSerializable) {
            $value = (string) $value;
        }
        if (is_string($value)) {
            if ($value === '' || self::isBinaryContentType($contentType) || str_contains(substr($value, 0, 512), "\0")) {
                return null;
            }

            return self::truncate($value, $limit);
        }
        if (is_scalar($value)) {
            return self::truncate(var_export($value, true), $limit);
        }
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if ($json === false || $json === '[]' || $json === '{}') {
            return null;
        }

        return self::truncate($json, $limit);
    }

    /**
     * `/a/b?x=1` becomes `['/a/b', 'x=1']`.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitUrl(string $url): array
    {
        $q = strpos($url, '?');
        $path = $q === false ? $url : substr($url, 0, $q);
        $query = $q === false ? '' : substr($url, $q + 1);

        return [$path === '' ? '/' : $path, $query];
    }

    /**
     * Lower-cased names; repeated headers joined with `, `.
     *
     * @param  iterable<mixed>  $headers  a name => value map, or name => list of values
     * @return array<string, string>
     */
    public static function flattenHeaders(iterable $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if ($value === null || ! is_string($name) && ! is_int($name)) {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            $parts = [];
            foreach ($values as $v) {
                if (is_scalar($v) || $v instanceof Stringable) {
                    $parts[] = (string) $v;
                }
            }
            if ($parts === []) {
                continue;
            }
            $key = strtolower((string) $name);
            $joined = implode(', ', $parts);
            $out[$key] = isset($out[$key]) ? $out[$key].', '.$joined : $joined;
        }

        return $out;
    }
}
