<?php

declare(strict_types=1);

namespace Trailscope;

use Throwable;

/**
 * The context of the request or job being handled: IDs, user, journey keys
 * and attributes. Log lines, spans, events and jobs started inside it carry
 * its request ID, trace ID, user and correlation keys.
 *
 * PHP handles one request (or one queue job) at a time per process, so the
 * current context is a static slot. Integrations activate a context when a
 * request or job starts and restore the previous one when it ends; long-running
 * servers (Octane) clear it between requests.
 */
final class Context
{
    private static ?Context $current = null;

    /**
     * @param  array<string, string>  $correlation  journey keys, e.g. `['account_id' => 'acc_1']`
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function __construct(
        public string $requestId,
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId = null,
        public ?string $userId = null,
        public array $correlation = [],
        public array $attributes = [],
        /** Route pattern, once the framework has matched one. */
        public ?string $route = null,
        /** The exception that failed the request, recorded by an error hook. */
        public ?Throwable $error = null,
    ) {}

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Makes `$context` the current context and returns the previous one, to be
     * restored with another call when the request or job ends.
     */
    public static function activate(?self $context): ?self
    {
        $previous = self::$current;
        self::$current = $context;

        return $previous;
    }

    /**
     * A new context continuing `$parent` (a context, or a serialized one from
     * job data): same request ID, trace, user and correlation keys, a new span.
     *
     * @param  self|array<mixed>|null  $parent
     */
    public static function continueFrom(self|array|null $parent): self
    {
        $p = is_array($parent) ? self::parse($parent) : $parent?->serialize();

        return new self(
            requestId: $p['requestId'] ?? Ids::requestId(),
            traceId: $p['traceId'] ?? Ids::traceId(),
            spanId: Ids::spanId(),
            parentSpanId: $p['spanId'] ?? null,
            userId: $p['userId'] ?? null,
            correlation: $p['correlation'] ?? [],
        );
    }

    public function setUser(string|int|null $userId): void
    {
        if ($userId !== null && $userId !== '') {
            $this->userId = (string) $userId;
        }
    }

    public function setCorrelation(string $key, string|int $value): void
    {
        if ($key !== '') {
            $this->correlation[$key] = (string) $value;
        }
    }

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        if ($key !== '') {
            $this->attributes[$key] = $value;
        }
    }

    /** Attaches the exception that failed the request (kept when the response is a 5xx). */
    public function recordError(Throwable $error): void
    {
        $this->error ??= $error;
    }

    /**
     * The part of the context that travels with a background job. The shape is
     * the same in every Trailscope SDK.
     *
     * @return array{requestId: string, traceId: string, spanId: string, userId?: string, correlation?: array<string, string>}
     */
    public function serialize(): array
    {
        $out = ['requestId' => $this->requestId, 'traceId' => $this->traceId, 'spanId' => $this->spanId];
        if ($this->userId !== null) {
            $out['userId'] = $this->userId;
        }
        if ($this->correlation !== []) {
            $out['correlation'] = $this->correlation;
        }

        return $out;
    }

    /**
     * Validates a serialized context (from job data or message headers).
     * Returns null when it isn't one; use continueFrom() to start a child.
     */
    public static function deserialize(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }
        $p = self::parse($value);
        if (! isset($p['requestId'], $p['traceId'], $p['spanId'])) {
            return null;
        }

        return new self(
            requestId: $p['requestId'],
            traceId: $p['traceId'],
            spanId: $p['spanId'],
            userId: $p['userId'] ?? null,
            correlation: $p['correlation'] ?? [],
        );
    }

    /**
     * @param  array<mixed>  $value
     * @return array{requestId?: string, traceId?: string, spanId?: string, userId?: string, correlation?: array<string, string>}
     */
    private static function parse(array $value): array
    {
        $out = [];
        foreach (['requestId', 'traceId', 'spanId', 'userId'] as $key) {
            $v = $value[$key] ?? null;
            if (is_string($v) && $v !== '' && strlen($v) <= 128) {
                $out[$key] = $v;
            }
        }
        if (isset($out['requestId']) && Ids::acceptRequestId($out['requestId']) === null) {
            unset($out['requestId']);
        }
        if (is_array($value['correlation'] ?? null)) {
            $correlation = [];
            foreach ($value['correlation'] as $k => $v) {
                if (is_string($k) && (is_string($v) || is_int($v))) {
                    $correlation[$k] = (string) $v;
                }
            }
            $out['correlation'] = $correlation;
        }

        return $out;
    }
}
