<?php

declare(strict_types=1);

namespace Trailscope\Laravel;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;
use Trailscope\Client;
use Trailscope\Context;
use Trailscope\Monolog\TrailscopeHandler;
use Trailscope\Trailscope;

/**
 * Auto-discovered. Registers the client (from config/trailscope.php and the
 * TRAILSCOPE_* environment variables), the request middleware, exception
 * capture, the queue integration, the `trailscope` log channel and the Octane
 * hooks.
 *
 * When the queue is sent:
 * - HTTP under PHP-FPM or `artisan serve`: in a terminating callback, after
 *   the response has been sent (with PHP-FPM, Laravel has already called
 *   fastcgi_finish_request(), so the client isn't waiting);
 * - Octane: at the end of a request once `flush_interval` has passed or
 *   `flush_at` items are queued, on ticks, and when the worker stops;
 * - queue workers: between jobs and while idle, on the same schedule;
 * - always: at shutdown (a shutdown function), for whatever is left.
 */
final class TrailscopeServiceProvider extends ServiceProvider
{
    private const OCTANE_EVENTS = 'Laravel\\Octane\\Events\\';

    /** Options that belong to this integration, not to the client. */
    private const LARAVEL_OPTIONS = ['middleware', 'queue', 'log_level'];

    private bool $octane = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/trailscope.php', 'trailscope');

        // The client lives for the whole process (Octane and queue workers
        // keep it between requests and jobs).
        $this->app->singleton(Client::class, static function (Application $app): Client {
            return Trailscope::client() ?? Trailscope::init(self::clientOptions($app));
        });
        $this->app->alias(Client::class, 'trailscope');

        $config = $this->app->make('config');
        if ($config instanceof Config && ! $config->has('logging.channels.trailscope')) {
            $level = $config->get('trailscope.log_level', 'info');
            $config->set('logging.channels.trailscope', [
                'driver' => 'monolog',
                'handler' => TrailscopeHandler::class,
                'handler_with' => ['name' => 'laravel'],
                'level' => is_string($level) ? $level : 'info',
                'replace_placeholders' => true,
            ]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/trailscope.php' => $this->app->configPath('trailscope.php')], 'trailscope-config');
        }
        try {
            $client = $this->app->make(Client::class);
            if (! $client->enabled) {
                return;
            }
            $config = $this->app->make('config');
            $events = $this->app->make('events');
            if (! $config instanceof Config || ! $events instanceof Dispatcher) {
                return;
            }
            if ($config->get('trailscope.middleware', true)) {
                $this->registerMiddleware();
            }
            $this->registerExceptionCapture($client);
            if ($config->get('trailscope.queue', true)) {
                (new QueueIntegration($client, $this->app->runningInConsole()))->register($events);
            }
            $events->listen(Authenticated::class, static function (Authenticated $event): void {
                $ctx = Context::current();
                if ($ctx !== null && $ctx->userId === null) {
                    $id = $event->user->getAuthIdentifier();
                    $ctx->setUser(is_string($id) || is_int($id) ? $id : null);
                }
            });
            $this->registerOctane($client, $events);
            $this->app->terminating(function () use ($client): void {
                // After the response has been sent.
                if ($this->octane) {
                    $client->flushIfDue();
                } else {
                    $client->flush();
                }
            });
        } catch (Throwable $e) {
            error_log('trailscope: could not start the Laravel integration: '.$e->getMessage());
        }
    }

    /**
     * Client options from config/trailscope.php: snake_case keys become the
     * client's camelCase options; null values fall back to the environment
     * variables and defaults.
     *
     * @return array<string, mixed>
     */
    public static function clientOptions(Application $app): array
    {
        $config = $app->make('config');
        $settings = $config instanceof Config ? $config->get('trailscope', []) : [];
        $options = [];
        foreach (is_array($settings) ? $settings : [] as $key => $value) {
            if ($value === null || ! is_string($key) || in_array($key, self::LARAVEL_OPTIONS, true)) {
                continue;
            }
            $options[Str::camel($key)] = $value;
        }
        if (! array_key_exists('stateFile', $options)) {
            $options['stateFile'] = $app->storagePath('framework/cache');
        }

        return $options;
    }

    private function registerMiddleware(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(TrailscopeMiddleware::class);
        }
    }

    /**
     * Reported exceptions are attached to the current request (sent with it
     * when the response is a 5xx) or job; outside both (console commands,
     * scheduled tasks) they become error events.
     */
    private function registerExceptionCapture(Client $client): void
    {
        $this->callAfterResolving(ExceptionHandler::class, static function (mixed $handler) use ($client): void {
            if (! is_object($handler) || ! method_exists($handler, 'reportable')) {
                return;
            }
            $handler->reportable(static function (Throwable $e) use ($client): void {
                try {
                    if ($client->isCarried($e)) {
                        return;
                    }
                    $ctx = Context::current();
                    if ($ctx !== null) {
                        $ctx->recordError($e);
                    } else {
                        $client->captureException($e);
                    }
                } catch (Throwable) {
                    // Reporting must go on.
                }
            });
        });
    }

    private function registerOctane(Client $client, Dispatcher $events): void
    {
        $events->listen(self::OCTANE_EVENTS.'RequestReceived', function (): void {
            $this->octane = true;
            Context::activate(null);
        });
        $events->listen(self::OCTANE_EVENTS.'RequestTerminated', static function (): void {
            Context::activate(null);
        });
        $events->listen(self::OCTANE_EVENTS.'TickReceived', static function () use ($client): void {
            $client->flushIfDue();
        });
        $events->listen(self::OCTANE_EVENTS.'WorkerStopping', static function () use ($client): void {
            $client->shutdown();
        });
    }
}
