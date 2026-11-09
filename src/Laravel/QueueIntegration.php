<?php

declare(strict_types=1);

namespace Trailscope\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Queue;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;
use Trailscope\Client;
use Trailscope\Context;
use Trailscope\JobRun;

/**
 * Carries the current request's context into every queued job (a
 * `trailscope` key in the job payload) and records each run in the worker as
 * a `kind=job` event with the job's name, duration, outcome and attempt,
 * linked to the request that queued it.
 *
 * Event order in the worker:
 * - success: JobProcessing, JobProcessed
 * - failure with a retry left: JobProcessing, JobExceptionOccurred (then the job is released)
 * - final failure: JobProcessing, JobFailed, JobExceptionOccurred
 * - `$this->fail()` or too many attempts: JobProcessing, JobFailed, JobProcessed
 * - sync connection: JobProcessing, JobExceptionOccurred, JobFailed
 * - timeout: JobProcessing, (JobFailed), JobTimedOut, WorkerStopping, then the worker kills itself
 */
final class QueueIntegration
{
    /** Payload key that carries the serialized context. */
    public const PAYLOAD_KEY = 'trailscope';

    /** @var array<int, array{run: JobRun, failed: Throwable|null}> keyed by the job object's id */
    private array $runs = [];

    public function __construct(
        private readonly Client $client,
        private readonly bool $console,
    ) {}

    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, array $payload): array {
            $ctx = Context::current();

            return $ctx === null ? [] : [self::PAYLOAD_KEY => $ctx->serialize()];
        });
        $events->listen(JobProcessing::class, $this->processing(...));
        $events->listen(JobProcessed::class, $this->processed(...));
        $events->listen(JobFailed::class, $this->failed(...));
        $events->listen(JobExceptionOccurred::class, $this->exceptionOccurred(...));
        $events->listen(JobTimedOut::class, $this->timedOut(...));
        // Between jobs, and every few seconds while idle: send what is due.
        $events->listen(Looping::class, function (Looping $_): void {
            $this->client->flushIfDue();
        });
        $events->listen(WorkerStopping::class, function (WorkerStopping $_): void {
            $this->client->flush();
        });
    }

    public function processing(JobProcessing $event): void
    {
        try {
            $job = $event->job;
            $payload = $job->payload();
            $attributes = ['queue' => (string) $job->getQueue(), 'connection' => $event->connectionName];
            $id = $job->getJobId();
            if (is_string($id) || is_int($id)) {
                $attributes['job_id'] = (string) $id;
            }
            $carried = is_array($payload) ? ($payload[self::PAYLOAD_KEY] ?? null) : null;
            $run = $this->client->startJob(
                self::name($job),
                attempt: max(1, (int) $job->attempts()),
                // Without a carried context the job starts a new one (not the
                // current request's: a sync job carries it in its payload).
                context: is_array($carried) ? $carried : [],
                attributes: $attributes,
            );
            $this->runs[spl_object_id($job)] = ['run' => $run, 'failed' => null];
        } catch (Throwable) {
            // Never break the worker because of Trailscope.
        }
    }

    public function processed(JobProcessed $event): void
    {
        $entry = $this->take($event->job);
        if ($entry === null) {
            return;
        }
        if ($entry['failed'] !== null) {
            $entry['run']->finish($entry['failed']);
        } elseif ($event->job->isReleased()) {
            $entry['run']->setAttribute('released', true);
            $entry['run']->finish(message: $entry['run']->name.' released back to the queue');
        } else {
            $entry['run']->finish();
        }
        $this->afterJob($event->connectionName);
    }

    public function failed(JobFailed $event): void
    {
        $key = spl_object_id($event->job);
        if (isset($this->runs[$key])) {
            // Recorded by JobProcessed or JobExceptionOccurred, whichever comes next.
            $this->runs[$key]['failed'] = $event->exception;
        }
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $entry = $this->take($event->job);
        if ($entry === null) {
            return;
        }
        $willRetry = $event->connectionName !== 'sync' && ! $event->job->hasFailed() && ! $event->job->isDeleted();
        $entry['run']->finish($event->exception, $willRetry);
        $this->afterJob($event->connectionName);
    }

    /** The worker kills itself right after this (WorkerStopping flushes first). */
    public function timedOut(JobTimedOut $event): void
    {
        $entry = $this->take($event->job);
        if ($entry === null) {
            return;
        }
        $error = $entry['failed'] ?? TimeoutExceededException::forJob($event->job);
        $entry['run']->finish($error, ! $event->job->hasFailed() && ! $event->job->isDeleted());
    }

    /** @return array{run: JobRun, failed: Throwable|null}|null */
    private function take(Job $job): ?array
    {
        $key = spl_object_id($job);
        $entry = $this->runs[$key] ?? null;
        unset($this->runs[$key]);

        return $entry;
    }

    private function afterJob(string $connection): void
    {
        // In a worker; never inside an HTTP request (sync jobs), where the
        // queue is sent after the response.
        if ($this->console && $connection !== 'sync') {
            $this->client->flushIfDue();
        }
    }

    private static function name(Job $job): string
    {
        try {
            $name = $job->resolveName();

            return is_string($name) && $name !== '' ? $name : 'job';
        } catch (Throwable) {
            return 'job';
        }
    }
}
