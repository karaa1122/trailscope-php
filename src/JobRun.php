<?php

declare(strict_types=1);

namespace Trailscope;

use Throwable;

/**
 * A job run started with Client::startJob(). Its context is current until
 * finish() records the run as a `kind=job` event and restores the previous
 * context.
 */
final class JobRun
{
    private bool $finished = false;

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function __construct(
        private readonly Client $client,
        public readonly string $name,
        public readonly Context $context,
        private readonly ?Context $previous,
        public readonly ?int $attempt,
        private array $attributes,
        private readonly float $timestamp,
        private readonly int|float $t0,
    ) {}

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Records the run: `ok` without an error, otherwise `error` with the
     * error's type, message and stack. A failed attempt that will be retried
     * is recorded with level `warn`.
     */
    public function finish(?Throwable $error = null, bool $willRetry = false, ?string $message = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            $failed = $error !== null;
            if ($message === null) {
                $message = $failed
                    ? $this->name.($willRetry ? ' failed (will retry): ' : ' failed: ').$error->getMessage()
                    : $this->name.' completed';
            }
            $this->client->captureEvent(
                $this->name,
                kind: 'job',
                level: $failed ? ($willRetry ? 'warn' : 'error') : 'info',
                message: $message,
                status: $failed ? 'error' : 'ok',
                durationMs: (hrtime(true) - $this->t0) / 1e6,
                attempt: $this->attempt,
                attributes: $this->attributes,
                error: $error,
                timestamp: $this->timestamp,
                context: $this->context,
            );
            if ($error !== null) {
                $this->client->markCarried($error);
            }
        } finally {
            if (Context::current() === $this->context) {
                Context::activate($this->previous);
            }
        }
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }
}
