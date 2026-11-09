<?php

declare(strict_types=1);

namespace Trailscope;

use stdClass;
use Throwable;

/**
 * Redaction of headers, bodies, query strings and IPs. Same rules as the Node
 * and Python SDKs.
 */
final class Redactor
{
    public const REDACTED = '[REDACTED]';

    public const DEFAULT_HEADERS = ['authorization', 'cookie', 'set-cookie', 'x-api-key'];

    public const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token',
        'otp', 'pin', 'cvv', 'card', 'card_number', 'iban', 'ssn',
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** @var array<string, true> */
    private array $headers = [];

    /** @var array<string, true> */
    private array $keys = [];

    /** @var list<list<string>> */
    private array $paths = [];

    /** @var list<string> */
    private array $patterns = [];

    private ?string $rawKeys = null;

    /**
     * @param  iterable<string>  $headers  extra header names
     * @param  iterable<string>  $keys  extra body keys, or dotted paths with `*` (`user.profile.dob`)
     * @param  iterable<string>  $patterns  PCRE patterns with delimiters, replaced in string values
     */
    public function __construct(
        iterable $headers = [],
        iterable $keys = [],
        iterable $patterns = [],
        public readonly bool $maskIp = true,
        bool $disableDefaults = false,
    ) {
        foreach ($disableDefaults ? [] : self::DEFAULT_HEADERS as $h) {
            $this->headers[$h] = true;
        }
        foreach ($headers as $h) {
            $this->headers[strtolower($h)] = true;
        }
        $simple = $disableDefaults ? [] : self::DEFAULT_KEYS;
        foreach ($keys as $k) {
            if (str_contains($k, '.')) {
                $this->paths[] = array_map(self::normKey(...), explode('.', $k));
            } elseif ($k !== '') {
                $simple[] = $k;
            }
        }
        foreach ($simple as $k) {
            $this->keys[self::normKey($k)] = true;
        }
        foreach ($patterns as $p) {
            if (@preg_match($p, '') !== false) {
                $this->patterns[] = $p;
            }
        }
        if ($simple !== []) {
            $alts = implode('|', array_map(
                static fn (string $k): string => implode('[_-]?', array_map(
                    static fn (string $part): string => preg_quote($part, '/'),
                    explode('_', $k),
                )),
                $simple,
            ));
            $this->rawKeys = '/("(?:'.$alts.')"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"?|[^,}\]\s]+)/i';
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $out[$lower] = isset($this->headers[$lower]) ? self::REDACTED : $this->applyPatterns($value);
        }

        return $out;
    }

    public function redactBody(string $body, string $contentType = ''): string
    {
        if ($body === '') {
            return $body;
        }
        $t = ltrim($body);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
            try {
                $parsed = json_decode($body, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->redactRawText($body);
            }
            $encoded = json_encode($this->redactValue($parsed), self::JSON_FLAGS);

            return $encoded === false ? $this->redactRawText($body) : $encoded;
        }
        if (str_contains(strtolower($contentType), 'x-www-form-urlencoded')) {
            return $this->redactUrlencoded($body);
        }

        return $this->redactRawText($body);
    }

    public function redactUrlencoded(string $s): string
    {
        $parts = [];
        foreach (explode('&', $s) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $parts[] = $pair;

                continue;
            }
            $k = substr($pair, 0, $eq);
            $v = substr($pair, $eq + 1);
            $parts[] = $this->isSensitiveFormKey(urldecode($k)) ? $k.'='.self::REDACTED : $k.'='.$this->applyPatterns($v);
        }

        return implode('&', $parts);
    }

    public function redactQuery(string $q): string
    {
        return $q === '' ? $q : $this->redactUrlencoded(ltrim($q, '?'));
    }

    /**
     * Redacts a structured value: decoded JSON bodies (objects as stdClass or
     * arrays) and job payloads.
     *
     * @param  list<string>  $path
     */
    public function redactValue(mixed $value, array $path = []): mixed
    {
        if ($value instanceof stdClass) {
            $out = new stdClass;
            foreach (get_object_vars($value) as $k => $child) {
                $out->{$k} = $this->redactChild((string) $k, $child, $path);
            }

            return $out;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->redactValue($item, [...$path, '*']), $value);
            }
            $out = [];
            foreach ($value as $k => $child) {
                $out[$k] = $this->redactChild((string) $k, $child, $path);
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->applyPatterns($value);
        }

        return $value;
    }

    public function applyPatterns(string $s): string
    {
        foreach ($this->patterns as $p) {
            $s = preg_replace($p, self::REDACTED, $s) ?? $s;
        }

        return $s;
    }

    public function ip(?string $ip): ?string
    {
        if ($ip === null || $ip === '' || ! $this->maskIp) {
            return $ip;
        }

        return self::maskIp($ip);
    }

    /** Zeroes the last IPv4 octet, or keeps the first 48 bits of an IPv6 address. */
    public static function maskIp(string $raw): string
    {
        $ip = trim($raw);
        if (str_starts_with($ip, '::ffff:') && str_contains($ip, '.')) {
            $ip = substr($ip, 7);
        }
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\d{1,3}$/', $ip, $m) === 1) {
            return $m[1].'.'.$m[2].'.'.$m[3].'.0';
        }
        if (str_contains($ip, ':')) {
            $head = explode('::', $ip)[0];
            $parts = array_values(array_filter(explode(':', $head), static fn (string $p): bool => $p !== ''));

            return implode(':', array_slice($parts, 0, 3)).'::';
        }

        return '';
    }

    /** `accessToken`, `access-token` and `ACCESS_TOKEN` all become `accesstoken`. */
    private static function normKey(string $k): string
    {
        return strtolower(str_replace(['_', '-'], '', $k));
    }

    /** @param list<string> $path */
    private function redactChild(string $key, mixed $child, array $path): mixed
    {
        $nk = self::normKey($key);
        $p = [...$path, $nk];
        if ((isset($this->keys[$nk]) || $this->pathMatches($p)) && $child !== null) {
            return self::REDACTED;
        }

        return $this->redactValue($child, $p);
    }

    /** Form keys, including PHP's bracket notation: `user[password]` checks `password`. */
    private function isSensitiveFormKey(string $key): bool
    {
        if (isset($this->keys[self::normKey($key)])) {
            return true;
        }

        return preg_match('/\[([^\[\]]*)\]$/', $key, $m) === 1 && isset($this->keys[self::normKey($m[1])]);
    }

    private function redactRawText(string $s): string
    {
        if ($this->rawKeys !== null) {
            $s = preg_replace_callback(
                $this->rawKeys,
                static fn (array $m): string => $m[1].'"'.self::REDACTED.'"',
                $s,
            ) ?? $s;
        }

        return $this->applyPatterns($s);
    }

    /** @param list<string> $path */
    private function pathMatches(array $path): bool
    {
        foreach ($this->paths as $p) {
            if (count($p) !== count($path)) {
                continue;
            }
            $ok = true;
            foreach ($p as $i => $want) {
                $got = $path[$i];
                if ($want !== '*' && $got !== '*' && $want !== $got) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
