<?php

declare(strict_types=1);

namespace Trailscope\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Trailscope\Client;
use Trailscope\Laravel\TrailscopeMiddleware;
use Trailscope\Tests\FakeIngest;

/**
 * Apps that compress JSON in their own middleware hand Trailscope, which runs
 * outermost, a response that is already gzipped.
 */
final class TrailscopeMiddlewareTest extends TestCase
{
    public function test_captures_the_decoded_body_of_a_gzipped_response(): void
    {
        $payload = ['data' => [['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Write the brief', 'token' => 'tok_123']]]];

        [$item, $wireSize] = $this->record(static fn (): Response => self::gzipped(new JsonResponse($payload)));

        self::assertIsString($item['resBody']);
        $payload['data'][0]['attributes']['token'] = '[REDACTED]';
        self::assertSame($payload, json_decode($item['resBody'], true));
        self::assertArrayNotHasKey('bodyTruncated', $item);
        self::assertSame($wireSize, $item['resSize']);
    }

    public function test_cuts_a_large_gzipped_body_at_the_limit(): void
    {
        $payload = ['data' => array_fill(0, 2000, ['type' => 'tasks', 'attributes' => ['title' => 'Write the brief']])];

        [$item] = $this->record(static fn (): Response => self::gzipped(new JsonResponse($payload)), ['maxBodyBytes' => 1024]);

        self::assertIsString($item['resBody']);
        self::assertSame(1024, strlen($item['resBody']));
        self::assertStringStartsWith('{"data":[{"type":"tasks"', $item['resBody']);
        self::assertTrue($item['bodyTruncated']);
    }

    public function test_skips_an_encoding_it_cannot_decode(): void
    {
        [$item] = $this->record(static function (): Response {
            $response = new JsonResponse(['done' => false]);
            $response->headers->set('Content-Encoding', 'br');

            return $response;
        });

        self::assertArrayNotHasKey('resBody', $item);
        self::assertSame(200, $item['status']);
    }

    /**
     * Runs one request through the middleware and returns the recorded item
     * and the response's size on the wire.
     *
     * @param  callable(): Response  $next
     * @param  array<string, mixed>  $options
     * @return array{array<mixed>, int}
     */
    private function record(callable $next, array $options = []): array
    {
        $ingest = new FakeIngest;
        $client = new Client($options + [
            'ingestKey' => 'trs_ingest_test',
            'service' => 'test',
            'captureBodies' => 'always',
            'remoteConfig' => false,
            'stateFile' => false,
            'transport' => $ingest,
        ]);
        $response = (new TrailscopeMiddleware($client, new Container))->handle(Request::create('/tasks'), $next(...));
        $client->flush();

        self::assertInstanceOf(Response::class, $response);
        self::assertCount(1, $ingest->items);

        return [$ingest->items[0], strlen((string) $response->getContent())];
    }

    /** What an app's compression middleware does to a JSON response. */
    private static function gzipped(Response $response): Response
    {
        $compressed = (string) gzencode((string) $response->getContent());
        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        return $response;
    }
}
