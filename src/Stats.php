<?php

declare(strict_types=1);

namespace Trailscope;

/** Counters of a client since it was created. */
final class Stats
{
    public function __construct(
        /** Items waiting in the queue. */
        public readonly int $queued,
        /** Items dropped because the queue was full, or left unsent at shutdown. */
        public readonly int $dropped,
        /** Items accepted by the server. */
        public readonly int $sent,
        /** Items the server (or serialization) rejected. */
        public readonly int $rejected,
        /** Batches that failed with a network error, 429 or 5xx and were kept for a retry. */
        public readonly int $failedBatches,
    ) {}
}
