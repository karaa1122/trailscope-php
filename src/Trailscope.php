<?php

declare(strict_types=1);

namespace Trailscope;

use Stringable;
use Throwable;

/**
 * The process-wide client and shortcuts to the current context.
 *
 *     Trailscope::init(['service' => 'tasks-api']);   // reads TRAILSCOPE_* from the environment
 *     Trailscope::setUser($userId);
 *     Trailscope::setCorrelation('project_id', $projectId);
 *
 * Every shortcut is a no-op without a client or outside a request or job,
 * except span() and trackJob(), which always run the callable.
 */
final class Trailscope
{
    private static ?Client $client = null;

    /**
     * Creates the process-wide client used by the framework integrations. A
     * previous client is flushed and shut down.
     *
     * @param  array<string, mixed>  $options  see Client
     */
    public static function init(array $options = []): Client
    {
        $previous = self::$client;
        self::$client = new Client($options);
        $previous?->shutdown();

        return self::$client;
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    public static function setClient(?Client $client): void
    {
        self::$client = $client;
    }

    public static function currentContext(): ?Context
    {
        return Context::current();
    }

    /** Attaches a user ID to the current request and everything it triggers. */
    public static function setUser(string|int|null $userId): void
    {
        Context::current()?->setUser($userId);
    }

    /** Adds a correlation (journey) key to the current request and everything it triggers. */
    public static function setCorrelation(string $key, string|int $value): void
    {
        Context::current()?->setCorrelation($key, $value);
    }

    /** Adds a free-form attribute to the current request. */
    public static function setAttribute(string $key, string|int|float|bool $value): void
    {
        Context::current()?->setAttribute($key, $value);
    }

    /** Attaches the exception that failed the current request (kept when the response is a 5xx). */
    public static function recordError(Throwable $error): void
    {
        Context::current()?->recordError($error);
    }

    /**
     * The current context's IDs, user and correlation keys, to store with a
     * job and restore in the worker with `trackJob(..., context: $carried)`.
     *
     * @return array{requestId: string, traceId: string, spanId: string, userId?: string, correlation?: array<string, string>}|null
     */
    public static function serializeContext(): ?array
    {
        return Context::current()?->serialize();
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public static function captureEvent(string $name, array $attributes = [], string|Stringable|null $message = null): void
    {
        self::$client?->captureEvent($name, message: $message, attributes: $attributes);
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public static function log(string $level, string|Stringable $message, array $attributes = [], ?Throwable $error = null): void
    {
        self::$client?->log($level, $message, $attributes, $error);
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public static function captureException(Throwable $error, array $attributes = []): void
    {
        self::$client?->captureException($error, attributes: $attributes);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  array<string, string|int|float|bool>  $attributes
     * @return T
     */
    public static function span(string $kind, string $name, callable $fn, array $attributes = []): mixed
    {
        return self::$client !== null ? self::$client->span($kind, $name, $fn, $attributes) : $fn();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  Context|array<mixed>|null  $context
     * @param  array<string, string|int|float|bool>  $attributes
     * @return T
     */
    public static function trackJob(string $name, callable $fn, ?int $attempt = null, Context|array|null $context = null, array $attributes = []): mixed
    {
        return self::$client !== null ? self::$client->trackJob($name, $fn, $attempt, $context, $attributes) : $fn();
    }

    /** Sends what is queued now (see Client::flush()). */
    public static function flush(?float $timeout = null): void
    {
        self::$client?->flush($timeout);
    }
}
