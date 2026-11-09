<?php

declare(strict_types=1);

namespace Trailscope\Transport;

final class Response
{
    public function __construct(
        /** HTTP status, or 0 for a network error. */
        public readonly int $status,
        public readonly string $body = '',
        /** The `Retry-After` header, if any. */
        public readonly ?string $retryAfter = null,
        /** The network error, when status is 0. */
        public readonly ?string $error = null,
    ) {}
}
