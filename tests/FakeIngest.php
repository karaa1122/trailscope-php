<?php

declare(strict_types=1);

namespace Trailscope\Tests;

use Trailscope\Transport\Response;
use Trailscope\Transport\Transport;

/** An ingest endpoint that keeps every item it receives. */
final class FakeIngest implements Transport
{
    /** @var list<array<mixed>> */
    public array $items = [];

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        $body ??= '';
        if (($headers['Content-Encoding'] ?? '') === 'gzip') {
            $body = (string) gzdecode($body);
        }
        foreach (explode("\n", $body) as $line) {
            $item = json_decode($line, true);
            if (is_array($item)) {
                $this->items[] = $item;
            }
        }

        return new Response(200, '{"accepted":1,"rejected":0}');
    }
}
