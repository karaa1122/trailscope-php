<?php

declare(strict_types=1);

namespace Trailscope;

use Closure;
use Throwable;

/**
 * One finished HTTP exchange, as framework integrations report it to
 * Client::recordHttp().
 */
final class HttpExchange
{
    /**
     * @param  float  $timestamp  epoch milliseconds when the request started
     * @param  string  $url  path with the query string, e.g. `/tasks/42?expand=owner`
     * @param  string|null  $route  route pattern, e.g. `/tasks/:id`; falls back to the context's route
     * @param  iterable<mixed>  $reqHeaders  name => value or name => list of values
     * @param  iterable<mixed>  $resHeaders  name => value or name => list of values
     * @param  mixed  $reqBody  a string, parsed fields, or a Closure returning one; only called when the capture policy keeps bodies
     * @param  mixed  $resBody  same as `$reqBody`
     * @param  Throwable|null  $error  falls back to the context's error
     */
    public function __construct(
        public float $timestamp,
        public float $durationMs,
        public string $method,
        public string $url,
        public int $status,
        public ?string $route = null,
        public iterable $reqHeaders = [],
        public iterable $resHeaders = [],
        public mixed $reqBody = null,
        public mixed $resBody = null,
        public ?string $ip = null,
        public ?int $reqSize = null,
        public ?int $resSize = null,
        public ?Throwable $error = null,
    ) {}

    /** Resolves a lazy body. */
    public static function body(mixed $body): mixed
    {
        return $body instanceof Closure ? $body() : $body;
    }
}
