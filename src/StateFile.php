<?php

declare(strict_types=1);

namespace Trailscope;

use Throwable;

/**
 * A small JSON file shared by the processes of one application: the project
 * settings from `/v1/config`, when they were fetched, and the retry pause
 * after failed sends.
 *
 * PHP-FPM keeps nothing between requests, so without it every request would
 * fetch `/v1/config` and, while the server is unreachable, wait for a
 * connection timeout. Writes are atomic (temporary file + rename); concurrent
 * writers are harmless because every field is a cache.
 *
 * @phpstan-type State array{config?: array<string, mixed>, configAt?: float, pauseUntil?: float, failures?: int, authWarnedAt?: float}
 */
final class StateFile
{
    public function __construct(public readonly string $path) {}

    /**
     * One file per endpoint, ingest key and OS user, in `$dir` (default: the
     * system temporary directory).
     */
    public static function defaultPath(string $endpoint, string $ingestKey, ?string $dir = null): string
    {
        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();

        return rtrim($dir ?? sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR
            .'trailscope-'.substr(hash('sha256', $endpoint."\0".$ingestKey."\0".$user), 0, 16).'.json';
    }

    /** @return State */
    public function read(): array
    {
        try {
            if (! is_file($this->path)) {
                return [];
            }
            $raw = @file_get_contents($this->path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
        } catch (Throwable) {
            return [];
        }
        if (! is_array($data)) {
            return [];
        }
        $state = [];
        if (is_array($data['config'] ?? null)) {
            /** @var array<string, mixed> $config */
            $config = $data['config'];
            $state['config'] = $config;
        }
        foreach (['configAt', 'pauseUntil', 'authWarnedAt'] as $key) {
            if (is_int($data[$key] ?? null) || is_float($data[$key] ?? null)) {
                $state[$key] = (float) $data[$key];
            }
        }
        if (is_int($data['failures'] ?? null)) {
            $state['failures'] = $data['failures'];
        }

        return $state;
    }

    /**
     * Merges `$changes` into the file.
     *
     * @param  State  $changes
     */
    public function update(array $changes): bool
    {
        try {
            $state = array_merge($this->read(), $changes);
            $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false) {
                return false;
            }
            $dir = dirname($this->path);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return false;
            }
            $tmp = $this->path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($tmp, $json) === false) {
                return false;
            }
            if (! @rename($tmp, $this->path)) {
                @unlink($tmp);

                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
