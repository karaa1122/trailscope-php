<?php

declare(strict_types=1);

/*
 * Measures what the Laravel middleware adds to the request path: context
 * setup, the request ID header, reading the matched route and recording the
 * request into the in-memory queue (redaction, serialization, gzip and the
 * network happen later, in the flush after the response). Synthetic
 * requests and responses keep the framework and network out; 2% of the
 * responses are 500s, so their bodies are captured.
 *
 * Run after `composer install` (dev dependencies): php bench/overhead.php
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Trailscope\Client;
use Trailscope\Laravel\TrailscopeMiddleware;
use Trailscope\Transport\Response as TransportResponse;
use Trailscope\Transport\Transport;

$n = 50_000;
$client = new Client([
    'ingestKey' => 'trs_ingest_bench',
    'service' => 'bench',
    // Default bound (10,000): nothing is flushed during the run, so the
    // oldest items are dropped, as in a process whose server is down.
    'remoteConfig' => false,
    'stateFile' => false,
    // Never reached during the measurement; the shutdown flush goes nowhere.
    'transport' => new class implements Transport
    {
        public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): TransportResponse
        {
            return new TransportResponse(200, '{"accepted":0,"rejected":0}');
        }
    },
]);
$middleware = new TrailscopeMiddleware($client, new Container);
$route = new Route(['POST'], 'projects/{projectId}/tasks', static fn () => null);
$payload = (string) json_encode([['id' => 't1', 'title' => 'Write the brief', 'done' => false]]);
$body = (string) json_encode(['title' => 'Draft roadmap', 'due_date' => '2026-01-15', 'tags' => ['a', 'b']]);

$request = static function (int $i) use ($route, $body): Request {
    $request = Request::create("/projects/prj_{$i}/tasks?expand=owner", 'POST', server: [
        'HTTP_HOST' => 'api.example.com',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiJ9.bench',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '198.51.100.7',
        'CONTENT_LENGTH' => (string) strlen($body),
    ], content: $body);
    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
};

// The route handler both variants run.
$handler = static fn (int $i): Closure => static fn (Request $r): JsonResponse => new JsonResponse($payload, $i % 50 === 0 ? 500 : 200, [], 0, true);

/** @return list<float> sorted durations in ms */
$measure = static function (bool $withSdk, int $count) use ($middleware, $request, $handler): array {
    $samples = [];
    for ($i = 0; $i < $count; $i++) {
        $req = $request($i);
        $next = $handler($i);
        $t = hrtime(true);
        $withSdk ? $middleware->handle($req, $next) : $next($req);
        $samples[] = (hrtime(true) - $t) / 1e6;
    }
    sort($samples);

    return $samples;
};

$measure(true, 5_000); // warm up
$measure(false, 5_000);
$base = $measure(false, $n);
$sdk = $measure(true, $n);
/** @param list<float> $s */
function percentile(array $s, float $p): float
{
    return $s[min(count($s) - 1, (int) floor(count($s) * $p))] ?? 0.0;
}

/**
 * @param  list<float>  $s
 * @return array{p50_ms: float, p99_ms: float, p999_ms: float}
 */
function summary(array $s): array
{
    return [
        'p50_ms' => round(percentile($s, 0.5), 4),
        'p99_ms' => round(percentile($s, 0.99), 4),
        'p999_ms' => round(percentile($s, 0.999), 4),
    ];
}

$result = [
    'iterations' => $n,
    'php' => PHP_VERSION,
    'handler_only' => summary($base),
    'with_middleware' => summary($sdk),
    'added_p50_ms' => round(percentile($sdk, 0.5) - percentile($base, 0.5), 4),
    'added_p99_ms' => round(percentile($sdk, 0.99) - percentile($base, 0.99), 4),
    'queued' => $client->stats()->queued,
    'dropped' => $client->stats()->dropped,
];
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
if ($result['added_p99_ms'] >= 0.2) {
    fwrite(STDERR, "p99 overhead is above the 0.2 ms budget\n");
    exit(1);
}
