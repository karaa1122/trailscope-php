# trailscope/trailscope

The [Trailscope](https://github.com/karaa1122/trailscope) SDK for PHP 8.2+ and Laravel 12 and 13: logs every request with its route, timing, user, journey keys and (on errors) bodies and stack, plus queue jobs and log lines, without making your users wait. The core has no dependencies; the Laravel integration is auto-discovered.

```bash
composer require trailscope/trailscope
```

## Laravel

```dotenv
TRAILSCOPE_ENDPOINT=https://logs.example.com
TRAILSCOPE_INGEST_KEY=trs_ingest_…
TRAILSCOPE_SERVICE=tasks-api
LOG_STACK=single,trailscope   # optional: send log lines too
```

Requests, reported exceptions, queued jobs (linked to the request that dispatched them) and Octane work out of the box. In request code:

```php
use Trailscope\Trailscope;

Trailscope::setUser($user->id);
Trailscope::setCorrelation('project_id', $projectId);
```

## Plain PHP

```php
$client = Trailscope\Trailscope::init(['service' => 'tasks-worker']);
$client->trackJob('report.build', fn () => build());
$client->log('info', 'report built', ['rows' => 1200]);
```

PHP has no background threads: capture calls only queue items in memory, and the queue is sent after the response has gone out, between jobs and at shutdown.

Full documentation: [docs/sdk-laravel.md](https://github.com/karaa1122/trailscope/blob/main/docs/sdk-laravel.md).

## License

Apache-2.0
