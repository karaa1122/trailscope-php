<?php

declare(strict_types=1);

/*
 * Trailscope settings (php artisan vendor:publish --tag=trailscope-config).
 *
 * Leave an option null to use the environment variable or the default; the
 * sampling and capture options left null follow the project's settings in the
 * Trailscope UI (Settings → Project), loaded from /v1/config.
 */

return [

    // Connection and identity. Without an ingest key nothing is sent.
    'endpoint' => env('TRAILSCOPE_ENDPOINT'),            // default http://localhost:8080
    'ingest_key' => env('TRAILSCOPE_INGEST_KEY'),
    'service' => env('TRAILSCOPE_SERVICE'),              // default unknown-service
    'environment' => env('TRAILSCOPE_ENVIRONMENT', env('APP_ENV')),
    'release' => env('TRAILSCOPE_RELEASE'),
    'enabled' => env('TRAILSCOPE_ENABLED'),              // TRAILSCOPE_ENABLED=0 turns the SDK off
    'debug' => env('TRAILSCOPE_DEBUG'),                  // TRAILSCOPE_DEBUG=1 logs SDK diagnostics to the PHP error log

    // Sampling and capture: null follows the project's settings.
    'sample_rate' => null,                               // 0..1; errors and slow requests are always kept
    'route_sample_rates' => null,                        // ['/search' => 0.25]
    'slow_ms' => null,                                   // default 1000
    'capture_bodies' => null,                            // 'errors' (default), 'always' or 'never'
    'max_body_bytes' => null,                            // default 8192, per body
    'ignore_routes' => null,                             // default /health, /healthz, /ready, /readyz, /livez
    'ignore_route_patterns' => [],                       // PCRE patterns, e.g. '#^/internal/#'
    'mask_ip' => null,                                   // default true

    // Redaction, added to the built-in rules.
    'redact_headers' => [],
    'redact_keys' => [],                                 // keys or dotted paths with *, e.g. 'user.profile.dob'
    'redact_patterns' => [],                             // PCRE patterns replaced in string values
    'disable_default_redaction' => false,

    'request_id_header' => 'x-request-id',

    // Queue and transport.
    'max_queue_size' => 10000,
    'flush_interval' => 1.0,                             // seconds, long-running processes
    'flush_at' => 500,
    'max_batch_size' => 1000,
    'timeout' => 3.0,                                    // seconds per HTTP call
    'connect_timeout' => 1.0,
    'shutdown_timeout' => 5.0,                           // budget of one flush
    'remote_config' => true,
    'state_file' => null,                                // a file or a directory; default storage/framework/cache; false to disable

    // Laravel integration.
    'middleware' => true,                                // add the request middleware to the global stack
    'queue' => true,                                     // carry the context into queued jobs and record each run
    'log_level' => env('TRAILSCOPE_LOG_LEVEL', 'info'),  // level of the "trailscope" log channel

];
