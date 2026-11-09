<?php

declare(strict_types=1);

namespace Trailscope\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Stringable;
use Throwable;
use Trailscope\Client;
use Trailscope\Trailscope;

/**
 * Sends Monolog records to Trailscope as `log` events, linked to the current
 * request or job (Monolog 3).
 *
 *     $logger->pushHandler(new TrailscopeHandler(level: Level::Info));
 *
 * The event name is the logger's channel (or `$name`), the `exception` in the
 * record's context becomes the error type, message and stack, and scalar
 * context values become attributes. A record whose exception is already
 * attached to a failed request or job (Laravel logs every reported exception)
 * is dropped, so the error isn't counted twice.
 */
final class TrailscopeHandler extends AbstractProcessingHandler
{
    private const MAX_ATTRIBUTES = 32;

    public function __construct(
        private readonly ?Client $client = null,
        int|string|Level $level = Level::Info,
        bool $bubble = true,
        private readonly ?string $name = null,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (str_starts_with($record->channel, 'trailscope')) {
            return;
        }
        $client = $this->client ?? Trailscope::client();
        if ($client === null) {
            return;
        }
        try {
            $exception = $record->context['exception'] ?? null;
            $attributes = [];
            foreach ([...$record->context, ...$record->extra] as $key => $value) {
                if (count($attributes) >= self::MAX_ATTRIBUTES) {
                    break;
                }
                if ($key === 'exception' || ! is_string($key)) {
                    continue;
                }
                if (is_scalar($value)) {
                    $attributes[$key] = $value;
                } elseif ($value instanceof Stringable) {
                    $attributes[$key] = (string) $value;
                }
            }
            $client->log(
                self::level($record->level),
                $record->message,
                $attributes,
                $exception instanceof Throwable ? $exception : null,
                $this->name ?? $record->channel,
                dedupe: true,
            );
        } catch (Throwable) {
            // Never let logging fail the application.
        }
    }

    private static function level(Level $level): string
    {
        return match (true) {
            $level->isHigherThan(Level::Warning) => 'error',
            $level === Level::Warning => 'warn',
            $level === Level::Debug => 'debug',
            default => 'info',
        };
    }
}
