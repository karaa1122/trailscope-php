<?php

declare(strict_types=1);

namespace Trailscope;

use Closure;
use SplDoublyLinkedList;
use Stringable;
use Throwable;
use Trailscope\Transport\CurlTransport;
use Trailscope\Transport\StreamTransport;
use Trailscope\Transport\Transport;
use WeakMap;

/**
 * Ships requests, spans, job runs and log lines to Trailscope.
 *
 * PHP has no background threads, so capture calls only append to a bounded
 * in-memory queue (the oldest items are dropped when it is full) and never
 * touch the network. The queue is sent by flush(): framework integrations
 * call it after the response has been sent, after queue jobs and on worker
 * ticks; it also runs at shutdown. Redaction, `beforeSend`, serialization and
 * compression happen in the flush.
 *
 * Options (camelCase keys) fall back to environment variables where one
 * exists: `TRAILSCOPE_ENDPOINT`, `TRAILSCOPE_INGEST_KEY`, `TRAILSCOPE_SERVICE`,
 * `TRAILSCOPE_ENVIRONMENT`, `TRAILSCOPE_RELEASE`, `TRAILSCOPE_ENABLED=0` and
 * `TRAILSCOPE_DEBUG=1`. See docs/sdk-laravel.md for the full list.
 */
final class Client
{
    public const VERSION = '0.4.1';

    public const DEFAULT_IGNORE_ROUTES = ['/health', '/healthz', '/ready', '/readyz', '/livez'];

    private const USER_AGENT = 'trailscope-php/'.self::VERSION;

    private const CONFIG_REFRESH_SECONDS = 300.0;

    private const AUTH_WARNING_SECONDS = 3600.0;

    private const MAX_PAUSE_SECONDS = 300.0;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const MAP_FIELDS = ['attributes', 'correlation', 'reqHeaders', 'resHeaders'];

    private const OPTIONS = [
        'endpoint', 'ingestKey', 'service', 'environment', 'release', 'enabled', 'debug',
        'maxQueueSize', 'flushInterval', 'flushAt', 'maxBatchSize', 'timeout', 'connectTimeout', 'shutdownTimeout',
        'sampleRate', 'routeSampleRates', 'slowMs', 'captureBodies', 'maxBodyBytes', 'ignoreRoutes', 'ignoreRoutePatterns',
        'requestIdHeader', 'redactHeaders', 'redactKeys', 'redactPatterns', 'maskIp', 'disableDefaultRedaction',
        'remoteConfig', 'stateFile', 'beforeSend', 'transport',
    ];

    public readonly string $endpoint;

    public readonly string $ingestKey;

    public readonly string $service;

    public readonly string $environment;

    public readonly string $release;

    public readonly bool $enabled;

    public readonly bool $debug;

    /** Lower-cased name of the request ID header, read from requests and set on responses. */
    public readonly string $requestIdHeader;

    public readonly int $maxQueueSize;

    public readonly float $flushInterval;

    public readonly int $flushAt;

    public readonly int $maxBatchSize;

    public readonly float $timeout;

    public readonly float $connectTimeout;

    public readonly float $shutdownTimeout;

    public readonly bool $remoteConfig;

    private float $sampleRate = 1.0;

    /** @var array<string, float> */
    private array $routeSampleRates = [];

    private float $slowMs = 1000.0;

    private string $captureBodies = BodyCapture::ERRORS;

    private int $maxBodyBytes = 8192;

    /** @var list<string> */
    private array $ignoreRoutes = self::DEFAULT_IGNORE_ROUTES;

    /** @var list<string> */
    private array $ignoreRoutePatterns = [];

    private Redactor $redactor;

    /** @var array{headers: list<string>, keys: list<string>, patterns: list<string>, maskIp: bool, disableDefaults: bool} */
    private array $redactArgs;

    /** @var array<string, true> options the application set itself: they win over /v1/config */
    private array $explicit = [];

    /** @var (Closure(array<string, mixed>): mixed)|null */
    private ?Closure $beforeSend = null;

    private ?Transport $transport = null;

    private ?StateFile $stateFile = null;

    /** @var SplDoublyLinkedList<array<string, mixed>> */
    private SplDoublyLinkedList $queue;

    private int $dropped = 0;

    private int $sent = 0;

    private int $rejected = 0;

    private int $failedBatches = 0;

    private int $failures = 0;

    private float $pauseUntil = 0.0;

    private float $configAt = 0.0;

    private float $lastFlush;

    private float $authWarnedAt = 0.0;

    private bool $closed = false;

    private bool $flushing = false;

    /** @var WeakMap<Throwable, true> errors already attached to a failed request, job or exception event */
    private WeakMap $carried;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->queue = new SplDoublyLinkedList;
        $this->carried = new WeakMap;
        $this->lastFlush = microtime(true);

        $this->debug = self::toBool($options['debug'] ?? null) ?? self::toBool(self::env('TRAILSCOPE_DEBUG')) ?? false;
        $this->endpoint = rtrim(self::toStr($options['endpoint'] ?? null) ?? self::env('TRAILSCOPE_ENDPOINT') ?? 'http://localhost:8080', '/');
        $this->ingestKey = self::toStr($options['ingestKey'] ?? null) ?? self::env('TRAILSCOPE_INGEST_KEY') ?? '';
        $this->service = self::toStr($options['service'] ?? null) ?? self::env('TRAILSCOPE_SERVICE') ?? 'unknown-service';
        $this->environment = self::toStr($options['environment'] ?? null) ?? self::env('TRAILSCOPE_ENVIRONMENT') ?? 'production';
        $this->release = self::toStr($options['release'] ?? null) ?? self::env('TRAILSCOPE_RELEASE') ?? '';
        $this->enabled = self::toBool($options['enabled'] ?? null) ?? self::toBool(self::env('TRAILSCOPE_ENABLED')) ?? true;

        $this->maxQueueSize = max(1, self::toInt($options['maxQueueSize'] ?? null) ?? 10_000);
        $this->flushInterval = max(0.0, self::toFloat($options['flushInterval'] ?? null) ?? 1.0);
        $this->flushAt = max(1, self::toInt($options['flushAt'] ?? null) ?? 500);
        $this->maxBatchSize = min(10_000, max(1, self::toInt($options['maxBatchSize'] ?? null) ?? 1000));
        $this->timeout = max(0.1, self::toFloat($options['timeout'] ?? null) ?? 3.0);
        $this->connectTimeout = min($this->timeout, max(0.05, self::toFloat($options['connectTimeout'] ?? null) ?? 1.0));
        $this->shutdownTimeout = max(0.1, self::toFloat($options['shutdownTimeout'] ?? null) ?? 5.0);
        $this->requestIdHeader = strtolower(self::toStr($options['requestIdHeader'] ?? null) ?? 'x-request-id');
        $this->remoteConfig = self::toBool($options['remoteConfig'] ?? null) ?? true;

        $this->applySettings([
            'sampleRate' => $options['sampleRate'] ?? null,
            'routeSampleRates' => $options['routeSampleRates'] ?? null,
            'slowMs' => $options['slowMs'] ?? null,
            'bodyCapture' => $options['captureBodies'] ?? null,
            'maxBodyBytes' => $options['maxBodyBytes'] ?? null,
            'ignoreRoutes' => $options['ignoreRoutes'] ?? null,
        ], explicit: true);
        $this->ignoreRoutePatterns = array_values(array_filter(
            self::toStrList($options['ignoreRoutePatterns'] ?? []),
            static fn (string $p): bool => @preg_match($p, '') !== false,
        ));

        $maskIp = self::toBool($options['maskIp'] ?? null);
        if ($maskIp !== null) {
            $this->explicit['maskIp'] = true;
        }
        $this->redactArgs = [
            'headers' => self::toStrList($options['redactHeaders'] ?? []),
            'keys' => self::toStrList($options['redactKeys'] ?? []),
            'patterns' => self::toStrList($options['redactPatterns'] ?? []),
            'maskIp' => $maskIp ?? true,
            'disableDefaults' => self::toBool($options['disableDefaultRedaction'] ?? null) ?? false,
        ];
        $this->redactor = self::makeRedactor($this->redactArgs);

        if (isset($options['beforeSend']) && is_callable($options['beforeSend'])) {
            $this->beforeSend = Closure::fromCallable($options['beforeSend']);
        }
        if (($options['transport'] ?? null) instanceof Transport) {
            $this->transport = $options['transport'];
        }
        foreach (array_keys($options) as $name) {
            if (! in_array($name, self::OPTIONS, true)) {
                $this->debugLog('unknown option "'.$name.'"');
            }
        }

        if ($this->enabled && $this->ingestKey === '') {
            $this->debugLog('no ingest key configured (TRAILSCOPE_INGEST_KEY); events will not be sent');
        }
        if ($this->active()) {
            $stateFile = $options['stateFile'] ?? null;
            if (is_string($stateFile) && $stateFile !== '') {
                // A directory holds one file per endpoint and key.
                $this->stateFile = new StateFile(is_dir($stateFile)
                    ? StateFile::defaultPath($this->endpoint, $this->ingestKey, $stateFile)
                    : $stateFile);
            } elseif ($stateFile !== false) {
                $this->stateFile = new StateFile(StateFile::defaultPath($this->endpoint, $this->ingestKey));
            }
            $this->loadState();
            register_shutdown_function(function (): void {
                $this->shutdown();
            });
        }
    }

    // ---- capture -----------------------------------------------------------------

    /**
     * Queues a request in the wire format (camelCase keys, see docs/ingest-protocol.md).
     *
     * @param  array<string, mixed>  $item
     */
    public function captureRequest(array $item): void
    {
        $item['type'] = 'request';
        $item['service'] = self::toStr($item['service'] ?? null) ?? $this->service;
        $this->enqueue($item);
    }

    /**
     * Records an event; IDs, user and correlation keys come from the current context.
     *
     * @param  string  $kind  `custom`, `job` or `log`
     * @param  string|null  $level  `debug`, `info`, `warn` or `error`
     * @param  string|null  $status  `ok`, `warn` or `error`
     * @param  array<string, string|int|float|bool>  $attributes
     * @param  int|float|null  $timestamp  epoch milliseconds
     */
    public function captureEvent(
        string $name,
        string $kind = 'custom',
        ?string $level = null,
        string|Stringable|null $message = null,
        ?string $status = null,
        ?float $durationMs = null,
        ?int $attempt = null,
        array $attributes = [],
        ?Throwable $error = null,
        int|float|null $timestamp = null,
        ?Context $context = null,
    ): void {
        $item = $this->eventItem($name, $kind, $level, $message, $status, $durationMs, $attempt, $attributes, $error, $timestamp, $context);
        if ($item !== null) {
            $this->enqueue($item);
        }
    }

    /**
     * An application log line, linked to the current request or job if there is one.
     *
     * @param  array<string, string|int|float|bool>  $attributes
     * @param  bool  $dedupe  drop the line at flush time when `$error` is attached to a failed request, job or exception event (used by the log bridges)
     */
    public function log(
        string $level,
        string|Stringable $message,
        array $attributes = [],
        ?Throwable $error = null,
        string $name = 'log',
        bool $dedupe = false,
    ): void {
        $item = $this->eventItem($name, 'log', $level, $message, null, null, null, $attributes, $error, null, null);
        if ($item === null) {
            return;
        }
        if ($dedupe && $error !== null) {
            $item['__dedupe'] = $error;
        }
        $this->enqueue($item);
    }

    /**
     * Reports an exception as an error event (outside an HTTP request, or a
     * handled one worth seeing).
     *
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function captureException(Throwable $error, string $name = 'exception', array $attributes = []): void
    {
        $this->captureEvent($name, kind: 'log', level: 'error', message: $error->getMessage(), status: 'error', attributes: $attributes, error: $error);
        $this->markCarried($error);
    }

    /**
     * Times `$fn` as a span of the current request and returns its result.
     *
     * @template T
     *
     * @param  string  $kind  `http`, `db`, `cache`, `queue`, `fn` or `external`
     * @param  callable(): T  $fn
     * @param  array<string, string|int|float|bool>  $attributes
     * @return T
     */
    public function span(string $kind, string $name, callable $fn, array $attributes = []): mixed
    {
        $ctx = Context::current();
        $start = microtime(true) * 1000;
        $t0 = hrtime(true);
        $status = 'ok';
        try {
            return $fn();
        } catch (Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $this->enqueue([
                'type' => 'span',
                'service' => $this->service,
                'traceId' => $ctx !== null ? $ctx->traceId : Ids::traceId(),
                'spanId' => Ids::spanId(),
                'parentSpanId' => $ctx?->spanId,
                'requestId' => $ctx?->requestId,
                'kind' => $kind,
                'name' => $name,
                'start' => round($start, 3),
                'durationMs' => round((hrtime(true) - $t0) / 1e6, 2),
                'status' => $status,
                'attributes' => $attributes === [] ? null : $attributes,
            ]);
        }
    }

    /**
     * Runs `$fn` as a background job inside its own context and records the
     * run as a `kind=job` event. Inside a request, the job belongs to it;
     * pass `$context` (a serialized context from Trailscope::serializeContext())
     * to link a job run in another process.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  Context|array<mixed>|null  $context
     * @param  array<string, string|int|float|bool>  $attributes
     * @return T
     */
    public function trackJob(string $name, callable $fn, ?int $attempt = null, Context|array|null $context = null, array $attributes = []): mixed
    {
        $run = $this->startJob($name, $attempt, $context, $attributes);
        try {
            $result = $fn();
        } catch (Throwable $e) {
            $run->finish($e);
            throw $e;
        }
        $run->finish();

        return $result;
    }

    /**
     * Starts a job run and makes its context current; call finish() on the
     * result when the job ends. For integrations that see the start and the
     * end of a job in separate callbacks.
     *
     * @param  Context|array<mixed>|null  $context  the parent: a context or a serialized one; defaults to the current context
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function startJob(string $name, ?int $attempt = null, Context|array|null $context = null, array $attributes = []): JobRun
    {
        $ctx = Context::continueFrom($context ?? Context::current());
        $previous = Context::activate($ctx);

        return new JobRun($this, $name, $ctx, $previous, $attempt, $attributes, microtime(true) * 1000, hrtime(true));
    }

    // ---- helpers for framework integrations ----------------------------------------

    /**
     * Builds the context of an incoming request from its headers.
     *
     * @param  callable(string): (string|null)  $header  returns a request header by lower-case name
     */
    public function contextFromHeaders(callable $header): Context
    {
        $tp = Ids::parseTraceparent($header('traceparent'));

        return new Context(
            requestId: Ids::acceptRequestId($header($this->requestIdHeader)) ?? Ids::requestId(),
            traceId: $tp['traceId'] ?? Ids::traceId(),
            spanId: Ids::spanId(),
            parentSpanId: $tp['parentSpanId'] ?? null,
        );
    }

    public function isIgnored(?string $routeOrPath): bool
    {
        if ($routeOrPath === null || $routeOrPath === '') {
            return false;
        }
        if (in_array($routeOrPath, $this->ignoreRoutes, true)) {
            return true;
        }
        foreach ($this->ignoreRoutePatterns as $pattern) {
            if (preg_match($pattern, $routeOrPath) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sample rate to record when the request is kept, or 0 when it is
     * dropped. Errors (status >= 400) and slow requests are always kept.
     */
    public function sample(string $route, int $status, float $durationMs, bool $hasError = false): float
    {
        if ($status >= 400 || $hasError || $durationMs >= $this->slowMs) {
            return 1.0;
        }
        $rate = $this->routeSampleRates[$route] ?? $this->sampleRate;
        if ($rate >= 1.0) {
            return 1.0;
        }

        return mt_rand() / (mt_getrandmax() + 1) < $rate ? $rate : 0.0;
    }

    /**
     * Records a finished HTTP exchange. Applies the ignore list, sampling and
     * the body capture policy. Error details are kept only for 5xx responses;
     * a handled 4xx is an expected outcome.
     */
    public function recordHttp(Context $ctx, HttpExchange $ex): void
    {
        if (! $this->active()) {
            return;
        }
        try {
            [$path, $query] = BodyCapture::splitUrl($ex->url);
            $route = $ex->route ?? $ctx->route;
            if ($this->isIgnored($route) || $this->isIgnored($path)) {
                return;
            }
            $status = $ex->status;
            $duration = round($ex->durationMs, 2);
            $failed = $status >= 500 ? ($ex->error ?? $ctx->error) : null;
            $rate = $this->sample($route ?? $path, $status, $duration, $failed !== null);
            if ($rate <= 0.0) {
                return;
            }
            $reqHeaders = BodyCapture::flattenHeaders($ex->reqHeaders);
            $resHeaders = BodyCapture::flattenHeaders($ex->resHeaders);
            $item = [
                'type' => 'request',
                'service' => $this->service,
                'timestamp' => (int) $ex->timestamp,
                'requestId' => $ctx->requestId,
                'traceId' => $ctx->traceId,
                'spanId' => $ctx->spanId,
                'method' => strtoupper($ex->method),
                'route' => $route,
                'path' => $path,
                'query' => $query === '' ? null : $query,
                'status' => $status,
                'durationMs' => $duration,
                'reqSize' => $ex->reqSize,
                'resSize' => $ex->resSize,
                'userId' => $ctx->userId,
                'ip' => $ex->ip,
                'userAgent' => $reqHeaders['user-agent'] ?? null,
                'reqHeaders' => $reqHeaders,
                'resHeaders' => $resHeaders,
                'attributes' => $ctx->attributes === [] ? null : $ctx->attributes,
                'correlation' => $ctx->correlation === [] ? null : $ctx->correlation,
                'sampleRate' => $rate < 1.0 ? $rate : null,
            ];
            if (BodyCapture::shouldCapture($this->captureBodies, $status, $duration, $this->slowMs)) {
                $limit = $this->maxBodyBytes;
                $rq = BodyCapture::serialize(HttpExchange::body($ex->reqBody), $reqHeaders['content-type'] ?? null, $limit);
                $encoding = strtolower(trim($resHeaders['content-encoding'] ?? ''));
                $rs = $encoding !== '' && $encoding !== 'identity'
                    ? null
                    : BodyCapture::serialize(HttpExchange::body($ex->resBody), $resHeaders['content-type'] ?? null, $limit);
                $item['reqBody'] = $rq[0] ?? null;
                $item['resBody'] = $rs[0] ?? null;
                if (($rq[1] ?? false) || ($rs[1] ?? false)) {
                    $item['bodyTruncated'] = true;
                }
                // Said separately so a cut response alone doesn't block replaying the request.
                $item['reqBodyTruncated'] = $rq[1] ?? false;
            }
            if ($failed !== null) {
                $item += ErrorFields::of($failed);
                $this->markCarried($failed);
            }
            $this->enqueue($item);
        } catch (Throwable $e) {
            $this->debugLog('could not record request: '.$e->getMessage());
        }
    }

    /** @internal Remembers that an error is attached to a request, job or exception event. */
    public function markCarried(Throwable $error): void
    {
        $this->carried[$error] = true;
    }

    /** Whether an error is already attached to a failed request, job or exception event. */
    public function isCarried(Throwable $error): bool
    {
        return isset($this->carried[$error]);
    }

    // ---- current settings (code options, else the project's settings) -------------

    public function sampleRate(): float
    {
        return $this->sampleRate;
    }

    public function slowMs(): float
    {
        return $this->slowMs;
    }

    /** `errors`, `always` or `never`. */
    public function captureBodies(): string
    {
        return $this->captureBodies;
    }

    public function maxBodyBytes(): int
    {
        return $this->maxBodyBytes;
    }

    /** @return list<string> */
    public function ignoreRoutes(): array
    {
        return $this->ignoreRoutes;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    // ---- lifecycle -----------------------------------------------------------------

    public function stats(): Stats
    {
        return new Stats($this->queue->count(), $this->dropped, $this->sent, $this->rejected, $this->failedBatches);
    }

    /**
     * Sends everything queued, in the calling process, for at most `$timeout`
     * seconds (default: `shutdownTimeout`). Call it when nobody is waiting:
     * after the response has been sent, or between jobs. Never throws.
     */
    public function flush(?float $timeout = null): void
    {
        if ($this->flushing || ! $this->active()) {
            return;
        }
        $this->flushing = true;
        try {
            $this->drain(microtime(true) + max(0.1, $timeout ?? $this->shutdownTimeout));
        } catch (Throwable $e) {
            $this->debugLog('flush failed: '.$e->getMessage());
        } finally {
            $this->flushing = false;
            $this->lastFlush = microtime(true);
        }
    }

    /**
     * Flushes when `flushAt` items are queued, `flushInterval` seconds have
     * passed since the last flush, or the project settings are due for a
     * refresh. For long-running processes: queue workers, Octane, daemons.
     */
    public function flushIfDue(): void
    {
        if ($this->flushing || ! $this->active()) {
            return;
        }
        $n = $this->queue->count();
        $now = microtime(true);
        if ($n >= $this->flushAt
            || ($n > 0 && $now - $this->lastFlush >= $this->flushInterval)
            || ($this->remoteConfig && $now - $this->configAt >= self::CONFIG_REFRESH_SECONDS)) {
            $this->flush();
        }
    }

    /**
     * Flushes what is left, waiting at most `$timeout` seconds (default
     * `shutdownTimeout`), and stops accepting items. Registered as a shutdown
     * function when the client is created.
     */
    public function shutdown(?float $timeout = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->flush($timeout ?? $this->shutdownTimeout);
        $this->closed = true;
        $left = $this->queue->count();
        if ($left > 0) {
            $this->dropped += $left;
            $this->queue = new SplDoublyLinkedList;
            $this->debugLog($left.' items left unsent at shutdown');
        }
    }

    /**
     * Fetches the project's SDK settings from `/v1/config` and applies them.
     * Returns false when the server couldn't be reached.
     */
    public function refreshConfig(): bool
    {
        if ($this->ingestKey === '') {
            return false;
        }
        $res = $this->transport()->send('GET', $this->endpoint.'/v1/config', [
            'Authorization' => 'Bearer '.$this->ingestKey,
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ], null, $this->timeout, $this->connectTimeout);
        $this->configAt = microtime(true);
        if ($res->status === 0) {
            $this->debugLog('could not load /v1/config: '.($res->error ?? 'network error'));

            return false;
        }
        $config = $res->status === 200 ? json_decode($res->body, true) : null;
        if (! is_array($config)) {
            $this->debugLog('could not load /v1/config: HTTP '.$res->status);

            return true;
        }
        /** @var array<string, mixed> $config */
        $this->applyRemoteConfig($config);
        $this->stateFile?->update(['config' => $config, 'configAt' => $this->configAt]);

        return true;
    }

    /**
     * Applies project settings (the `/v1/config` response) to every option the
     * application didn't set itself.
     *
     * @param  array<string, mixed>  $config
     */
    public function applyRemoteConfig(array $config): void
    {
        $this->applySettings($config, explicit: false);
        $mask = $config['maskIp'] ?? null;
        if (! isset($this->explicit['maskIp']) && is_bool($mask) && $mask !== $this->redactArgs['maskIp']) {
            $this->redactArgs['maskIp'] = $mask;
            $this->redactor = self::makeRedactor($this->redactArgs);
        }
    }

    // ---- internals -----------------------------------------------------------------

    private function active(): bool
    {
        return $this->enabled && $this->ingestKey !== '' && ! $this->closed;
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     * @return array<string, mixed>|null
     */
    private function eventItem(
        string $name,
        string $kind,
        ?string $level,
        string|Stringable|null $message,
        ?string $status,
        ?float $durationMs,
        ?int $attempt,
        array $attributes,
        ?Throwable $error,
        int|float|null $timestamp,
        ?Context $context,
    ): ?array {
        if (! $this->active()) {
            return null;
        }
        try {
            $ctx = $context ?? Context::current();
            $item = [
                'type' => 'event',
                'service' => $this->service,
                'kind' => $kind,
                'name' => $name,
                'level' => $level === null ? null : self::level($level),
                'message' => $message === null ? null : (string) $message,
                'status' => $status,
                'durationMs' => $durationMs === null ? null : round($durationMs, 2),
                'attempt' => $attempt,
                'attributes' => $attributes === [] ? null : $attributes,
                'timestamp' => $timestamp === null ? null : (int) $timestamp,
            ];
            if ($ctx !== null) {
                $item['requestId'] = $ctx->requestId;
                $item['traceId'] = $ctx->traceId;
                $item['userId'] = $ctx->userId;
                $item['correlation'] = $ctx->correlation === [] ? null : $ctx->correlation;
            }
            if ($error !== null) {
                $item += ErrorFields::of($error);
            }

            return $item;
        } catch (Throwable $e) {
            $this->debugLog('could not record event: '.$e->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $item */
    private function enqueue(array $item): void
    {
        if (! $this->active()) {
            return;
        }
        if ($item['type'] !== 'span') {
            if (($item['environment'] ?? null) === null || $item['environment'] === '') {
                $item['environment'] = $this->environment;
            }
            if ($this->release !== '' && ($item['release'] ?? null) === null) {
                $item['release'] = $this->release;
            }
            $item['timestamp'] ??= (int) floor(microtime(true) * 1000);
        }
        if ($this->queue->count() >= $this->maxQueueSize) {
            $this->queue->shift();
            $this->dropped++;
        }
        $this->queue->push($item);
    }

    /**
     * Settings shared by code options and /v1/config (wire names).
     *
     * @param  array<string, mixed>  $c
     */
    private function applySettings(array $c, bool $explicit): void
    {
        $use = function (string $name, mixed $value) use ($explicit): bool {
            if ($value === null || (! $explicit && isset($this->explicit[$name]))) {
                return false;
            }
            if ($explicit) {
                $this->explicit[$name] = true;
            }

            return true;
        };
        $rate = self::toFloat($c['sampleRate'] ?? null);
        if ($use('sampleRate', $rate) && $rate !== null) {
            $this->sampleRate = self::clamp($rate);
        }
        $rates = $c['routeSampleRates'] ?? null;
        if (is_array($rates) && $use('routeSampleRates', $rates)) {
            $this->routeSampleRates = [];
            foreach ($rates as $route => $r) {
                $r = self::toFloat($r);
                if ($r !== null) {
                    $this->routeSampleRates[(string) $route] = self::clamp($r);
                }
            }
        }
        $slow = self::toFloat($c['slowMs'] ?? null);
        if ($slow !== null && $slow > 0 && $use('slowMs', $slow)) {
            $this->slowMs = $slow;
        }
        $policy = $c['bodyCapture'] ?? null;
        if (in_array($policy, [BodyCapture::ERRORS, BodyCapture::ALWAYS, BodyCapture::NEVER], true) && $use('captureBodies', $policy)) {
            $this->captureBodies = $policy;
        }
        $max = self::toInt($c['maxBodyBytes'] ?? null);
        if ($max !== null && $max >= 0 && $use('maxBodyBytes', $max)) {
            $this->maxBodyBytes = $max;
        }
        $routes = $c['ignoreRoutes'] ?? null;
        if (is_array($routes) && $use('ignoreRoutes', $routes)) {
            $this->ignoreRoutes = self::toStrList($routes);
        }
    }

    private function loadState(): void
    {
        if ($this->stateFile === null) {
            return;
        }
        $state = $this->stateFile->read();
        if ($this->remoteConfig && isset($state['config'])) {
            $this->applyRemoteConfig($state['config']);
        }
        $now = microtime(true);
        $this->configAt = min($now, $state['configAt'] ?? 0.0);
        $pause = $state['pauseUntil'] ?? 0.0;
        $this->pauseUntil = $pause > $now + self::MAX_PAUSE_SECONDS ? 0.0 : $pause;
        $this->failures = max(0, $state['failures'] ?? 0);
        $this->authWarnedAt = $state['authWarnedAt'] ?? 0.0;
    }

    private function drain(float $deadline): void
    {
        if (microtime(true) < $this->pauseUntil) {
            return;
        }
        if ($this->remoteConfig && microtime(true) - $this->configAt >= self::CONFIG_REFRESH_SECONDS && $this->claimConfigRefresh()) {
            if (! $this->refreshConfig()) {
                // The server is unreachable: don't wait for a second timeout now.
                $this->backoff(null);

                return;
            }
        }
        while (! $this->queue->isEmpty()) {
            $now = microtime(true);
            if ($now < $this->pauseUntil || $now >= $deadline) {
                return;
            }
            $batch = $this->take($this->maxBatchSize);
            $prepared = $this->prepare($batch);
            if ($prepared === []) {
                continue;
            }
            [$result, $retryAfter] = $this->send($prepared, min($this->timeout, max(0.1, $deadline - $now)));
            if ($result === 'ok') {
                if ($this->failures > 0 || $this->pauseUntil > 0.0) {
                    $this->failures = 0;
                    $this->pauseUntil = 0.0;
                    $this->stateFile?->update(['failures' => 0, 'pauseUntil' => 0.0]);
                }

                continue;
            }
            if ($result === 'drop') {
                continue;
            }
            $this->failedBatches++;
            $this->putBack($batch);
            $this->backoff($retryAfter);

            return;
        }
    }

    /**
     * With a state file, only one process refreshes the settings: the others
     * pick up what it saved.
     */
    private function claimConfigRefresh(): bool
    {
        if ($this->stateFile === null) {
            return true;
        }
        $state = $this->stateFile->read();
        $now = microtime(true);
        $at = $state['configAt'] ?? 0.0;
        if ($now - $at < self::CONFIG_REFRESH_SECONDS && $at <= $now) {
            $this->configAt = $at;
            if (isset($state['config'])) {
                $this->applyRemoteConfig($state['config']);
            }

            return false;
        }
        $this->configAt = $now;
        $this->stateFile->update(['configAt' => $now]);

        return true;
    }

    /** Pauses sending with exponential backoff and jitter (1 s doubling to 60 s), at least `$retryAfter`. */
    private function backoff(?float $retryAfter): void
    {
        $this->failures++;
        $base = min(60.0, 2.0 ** min(10, $this->failures - 1));
        $jittered = $base / 2 + (mt_rand() / (mt_getrandmax() + 1)) * $base / 2;
        $pause = min(self::MAX_PAUSE_SECONDS, max($retryAfter ?? 0.0, $jittered));
        $this->pauseUntil = microtime(true) + $pause;
        $this->stateFile?->update(['failures' => $this->failures, 'pauseUntil' => $this->pauseUntil]);
        $this->debugLog(sprintf('send failed; pausing for %.1f s', $pause));
    }

    /** @return list<array<string, mixed>> */
    private function take(int $n): array
    {
        $out = [];
        while ($n-- > 0 && ! $this->queue->isEmpty()) {
            $out[] = $this->queue->shift();
        }

        return $out;
    }

    /**
     * Puts a failed batch back at the front, as far as room allows (keeping the newest).
     *
     * @param  list<array<string, mixed>>  $batch
     */
    private function putBack(array $batch): void
    {
        $room = $this->maxQueueSize - $this->queue->count();
        $keep = $room <= 0 ? [] : array_slice($batch, max(0, count($batch) - $room));
        $this->dropped += count($batch) - count($keep);
        foreach (array_reverse($keep) as $item) {
            $this->queue->unshift($item);
        }
    }

    /**
     * Redaction, `beforeSend` and removal of empty fields, off the request path.
     *
     * @param  list<array<string, mixed>>  $batch
     * @return list<array<string, mixed>>
     */
    private function prepare(array $batch): array
    {
        $r = $this->redactor;
        $out = [];
        foreach ($batch as $original) {
            $dedupe = $original['__dedupe'] ?? null;
            if ($dedupe instanceof Throwable && $this->isCarried($dedupe)) {
                continue;
            }
            unset($original['__dedupe']);
            $item = array_filter($original, static fn (mixed $v): bool => $v !== null);
            try {
                if ($item['type'] === 'request') {
                    $ct = self::header($item['reqHeaders'] ?? null, 'content-type');
                    $resCt = self::header($item['resHeaders'] ?? null, 'content-type');
                    foreach (['reqHeaders', 'resHeaders'] as $key) {
                        if (is_array($item[$key] ?? null)) {
                            $item[$key] = $r->redactHeaders(BodyCapture::flattenHeaders($item[$key]));
                        }
                    }
                    if (is_string($item['reqBody'] ?? null)) {
                        $item['reqBody'] = $r->redactBody($item['reqBody'], $ct);
                    }
                    if (is_string($item['resBody'] ?? null)) {
                        $item['resBody'] = $r->redactBody($item['resBody'], $resCt);
                    }
                    if (is_string($item['query'] ?? null)) {
                        $item['query'] = $r->redactQuery($item['query']);
                    }
                    if (is_string($item['ip'] ?? null)) {
                        $item['ip'] = $r->ip($item['ip']);
                    }
                    if (is_string($item['errorMessage'] ?? null)) {
                        $item['errorMessage'] = $r->applyPatterns($item['errorMessage']);
                    }
                } elseif ($item['type'] === 'event') {
                    foreach (['message', 'errorMessage'] as $key) {
                        if (is_string($item[$key] ?? null)) {
                            $item[$key] = $r->applyPatterns($item[$key]);
                        }
                    }
                }
                if ($this->beforeSend !== null) {
                    $result = ($this->beforeSend)($item);
                    if (! is_array($result) || $result === []) {
                        continue;
                    }
                    /** @var array<string, mixed> $result */
                    $item = $result;
                }
            } catch (Throwable $e) {
                $this->debugLog('could not prepare an item: '.$e->getMessage());

                continue;
            }
            // Maps must serialize as JSON objects, even when empty or list-like.
            foreach (self::MAP_FIELDS as $key) {
                if (! array_key_exists($key, $item)) {
                    continue;
                }
                if (is_array($item[$key]) && $item[$key] !== []) {
                    $item[$key] = (object) $item[$key];
                } elseif (! is_object($item[$key])) {
                    unset($item[$key]);
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: 'ok'|'retry'|'drop', 1: float|null}
     */
    private function send(array $items, float $timeout): array
    {
        $lines = [];
        foreach ($items as $item) {
            $json = json_encode($item, self::JSON_FLAGS);
            if ($json === false) {
                $this->rejected++;

                continue;
            }
            $lines[] = $json;
        }
        if ($lines === []) {
            return ['drop', null];
        }
        $body = implode("\n", $lines);
        $headers = [
            'Authorization' => 'Bearer '.$this->ingestKey,
            'Content-Type' => 'application/x-ndjson',
            'User-Agent' => self::USER_AGENT,
        ];
        if (function_exists('gzencode')) {
            $gz = gzencode($body, 6);
            if (is_string($gz)) {
                $body = $gz;
                $headers['Content-Encoding'] = 'gzip';
            }
        }
        $res = $this->transport()->send('POST', $this->endpoint.'/v1/ingest', $headers, $body, $timeout, min($this->connectTimeout, $timeout));
        $status = $res->status;
        if ($status === 0) {
            $this->debugLog('network error: '.($res->error ?? 'unknown'));

            return ['retry', null];
        }
        if ($status === 200 || $status === 207) {
            $parsed = json_decode($res->body, true);
            $accepted = is_array($parsed) && is_int($parsed['accepted'] ?? null) ? $parsed['accepted'] : count($lines);
            $rejected = is_array($parsed) && is_int($parsed['rejected'] ?? null) ? $parsed['rejected'] : 0;
            $this->sent += $accepted;
            if ($rejected > 0) {
                $this->rejected += $rejected;
                $errors = is_array($parsed) && is_array($parsed['errors'] ?? null) ? $parsed['errors'] : [];
                $first = is_array($errors[0] ?? null) ? $errors[0] : [];
                $this->debugLog('server rejected '.$rejected.' items: '.(is_string($first['message'] ?? null) ? $first['message'] : ''));
            }

            return ['ok', null];
        }
        if ($status === 429 || $status >= 500) {
            return ['retry', self::retryAfter($res->retryAfter)];
        }
        if ($status === 401 || $status === 403) {
            $this->warnAuth($status);
        }
        $this->rejected += count($lines);
        $this->debugLog('batch dropped: HTTP '.$status);

        return ['drop', null];
    }

    private function warnAuth(int $status): void
    {
        $now = microtime(true);
        if ($now - $this->authWarnedAt < self::AUTH_WARNING_SECONDS) {
            return;
        }
        $this->authWarnedAt = $now;
        $this->stateFile?->update(['authWarnedAt' => $now]);
        error_log('trailscope: ingest key rejected (HTTP '.$status.'); check TRAILSCOPE_INGEST_KEY');
    }

    private function transport(): Transport
    {
        return $this->transport ??= CurlTransport::available() ? new CurlTransport : new StreamTransport;
    }

    private function debugLog(string $message): void
    {
        if ($this->debug) {
            error_log('trailscope: '.$message);
        }
    }

    /** @param array{headers: list<string>, keys: list<string>, patterns: list<string>, maskIp: bool, disableDefaults: bool} $a */
    private static function makeRedactor(array $a): Redactor
    {
        return new Redactor($a['headers'], $a['keys'], $a['patterns'], $a['maskIp'], $a['disableDefaults']);
    }

    private static function retryAfter(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = strtotime($value);

        return $at === false ? null : max(0.0, $at - microtime(true));
    }

    private static function level(string $level): string
    {
        return match (strtolower($level)) {
            'warning', 'warn' => 'warn',
            'error', 'err', 'critical', 'crit', 'alert', 'emergency', 'emerg', 'fatal' => 'error',
            'debug', 'trace' => 'debug',
            default => 'info',
        };
    }

    private static function header(mixed $headers, string $name): string
    {
        $value = is_array($headers) ? ($headers[$name] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    private static function clamp(float $rate): float
    {
        return min(1.0, max(0.0, $rate));
    }

    private static function env(string $name): ?string
    {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_scalar($v) && ! is_bool($v) && (string) $v !== '' ? (string) $v : null;
    }

    private static function toStr(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function toBool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return match (strtolower(trim($v))) {
                '1', 'true', 'on', 'yes' => true,
                '0', 'false', 'off', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    private static function toFloat(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float) $v : null;
    }

    private static function toInt(mixed $v): ?int
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (int) $v : null;
    }

    /** @return list<string> */
    private static function toStrList(mixed $v): array
    {
        if (is_string($v)) {
            $v = [$v];
        }
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = trim($s);
            }
        }

        return $out;
    }
}
