<?php

declare(strict_types=1);

namespace Trailscope\Transport;

/**
 * Sends one HTTP request. Implementations never throw: a network error is a
 * Response with status 0.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  float  $timeout  total seconds
     * @param  float  $connectTimeout  seconds to establish the connection
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response;
}
