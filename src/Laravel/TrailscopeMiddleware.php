<?php

declare(strict_types=1);

namespace Trailscope\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Trailscope\BodyCapture;
use Trailscope\Client;
use Trailscope\Context;
use Trailscope\HttpExchange;

/**
 * Records every HTTP request. The service provider adds it first in the
 * global middleware stack, so it times everything and sees the final
 * response; it can also be added by hand (`'middleware' => false` in
 * config/trailscope.php).
 *
 * Recording only appends to the client's queue. The queue is sent after the
 * response has gone out (a terminating callback), so the client never waits.
 */
final class TrailscopeMiddleware
{
    public function __construct(
        private readonly Client $client,
        private readonly Container $app,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->client->enabled) {
            return $next($request);
        }
        $timestamp = microtime(true) * 1000;
        $t0 = hrtime(true);
        try {
            $ctx = $this->client->contextFromHeaders(static fn (string $name): ?string => $request->headers->get($name));
        } catch (Throwable) {
            return $next($request);
        }
        Context::activate($ctx);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Laravel normally turns exceptions into responses before they get here.
            $ctx->recordError($e);
            $this->record($request, null, $ctx, $timestamp, $t0);

            throw $e;
        }
        if ($response instanceof Response) {
            try {
                $name = self::headerName($this->client->requestIdHeader);
                if (! $response->headers->has($name)) {
                    $response->headers->set($name, $ctx->requestId);
                }
            } catch (Throwable) {
                // Never fail the response because of Trailscope.
            }
        }
        $this->record($request, $response instanceof Response ? $response : null, $ctx, $timestamp, $t0);

        // The context stays current until the next request (or Octane's reset),
        // so jobs dispatched after the response and terminating callbacks
        // still belong to this request.
        return $response;
    }

    /**
     * `projects/{project}/tasks` becomes `/projects/:project/tasks`; optional
     * parameters (`{id?}`) become `:id`.
     */
    public static function routeTemplate(string $uri): string
    {
        $template = preg_replace('/\{([^}?]+)\??\}/', ':$1', $uri) ?? $uri;

        return '/'.ltrim($template, '/');
    }

    private function record(Request $request, ?Response $response, Context $ctx, float $timestamp, int|float $t0): void
    {
        try {
            $duration = (hrtime(true) - $t0) / 1e6;
            $route = $request->route();
            $template = $route instanceof Route && ! $route->isFallback ? self::routeTemplate($route->uri()) : null;
            if ($template !== null) {
                $ctx->route ??= $template;
            }
            if ($ctx->userId === null) {
                $ctx->setUser($this->resolvedUserId());
            }
            $limit = $this->client->maxBodyBytes();
            $content = $response?->getContent();
            $contentLength = $request->server->get('CONTENT_LENGTH');
            $resLength = $response?->headers->get('Content-Length');

            $this->client->recordHttp($ctx, new HttpExchange(
                timestamp: $timestamp,
                durationMs: $duration,
                method: $request->getMethod(),
                url: $request->getRequestUri(),
                status: $response?->getStatusCode() ?? 500,
                route: $template,
                reqHeaders: $request->headers->all(),
                resHeaders: $response?->headers->all() ?? [],
                reqBody: fn (): mixed => $this->requestBody($request, $limit),
                resBody: static fn (): ?string => self::responseBody($response, $content, $limit),
                ip: $request->ip(),
                reqSize: is_numeric($contentLength) ? (int) $contentLength : null,
                resSize: is_numeric($resLength) ? (int) $resLength : (is_string($content) ? strlen($content) : null),
            ));
        } catch (Throwable) {
            // Recording must never break the request.
        }
    }

    /** The authenticated user, only if the app already resolved it (no extra query). */
    private function resolvedUserId(): ?string
    {
        if (! $this->app->resolved('auth')) {
            return null;
        }
        $auth = $this->app->make('auth');
        if (! $auth instanceof AuthFactory || (method_exists($auth, 'hasResolvedGuards') && ! $auth->hasResolvedGuards())) {
            return null;
        }
        $guard = $auth->guard();
        if (! $guard->hasUser()) {
            return null;
        }
        $id = $guard->id();

        return is_string($id) || is_int($id) ? (string) $id : null;
    }

    /**
     * The request body, read only when the capture policy keeps it: parsed
     * fields for multipart forms (files listed by name, size and type, never
     * their content), at most `$limit + 1` bytes of other bodies.
     */
    private function requestBody(Request $request, int $limit): mixed
    {
        $type = (string) $request->headers->get('Content-Type', '');
        if (str_starts_with(strtolower($type), 'multipart/form-data')) {
            $fields = $request->request->all();
            foreach ($request->files->all() as $key => $files) {
                $fields[$key] = self::describeFiles($files);
            }

            return $fields === [] ? null : $fields;
        }
        if (BodyCapture::isBinaryContentType($type)) {
            return null;
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $limit + 1);

        return is_string($body) && $body !== '' ? $body : null;
    }

    private static function describeFiles(mixed $files): mixed
    {
        if (is_array($files)) {
            $out = array_map(self::describeFiles(...), $files);

            return count($out) === 1 && array_is_list($out) ? $out[0] : $out;
        }
        if ($files instanceof UploadedFile) {
            return sprintf('[file %s, %d bytes, %s]', $files->getClientOriginalName(), (int) $files->getSize(), $files->getClientMimeType());
        }

        return null;
    }

    private static function responseBody(?Response $response, string|false|null $content, int $limit): ?string
    {
        if ($response === null || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }
        if (! is_string($content) || $content === '') {
            return null;
        }
        // A compressed body stays whole: its first `$limit + 1` bytes could
        // decode to less than that, and decoding stops at the limit anyway.
        if ($response->headers->has('Content-Encoding')) {
            return $content;
        }

        return substr($content, 0, $limit + 1);
    }

    private static function headerName(string $lower): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', $lower)));
    }
}
