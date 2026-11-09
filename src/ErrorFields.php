<?php

declare(strict_types=1);

namespace Trailscope;

use Throwable;

/**
 * `errorType`, `errorMessage` and `errorStack` for the wire format.
 *
 * The stack is written innermost frame first as `at Class->method (file:line)`
 * lines, the format the server's error grouping reads (line numbers are
 * ignored when grouping). Previous exceptions follow as `Caused by:` sections.
 * Function arguments are never included.
 */
final class ErrorFields
{
    private const MAX_FRAMES = 100;

    private const MAX_CAUSES = 5;

    /** @return array{errorType: string, errorMessage: string, errorStack: string} */
    public static function of(Throwable $error): array
    {
        $lines = [];
        $e = $error;
        for ($depth = 0; $e !== null && $depth <= self::MAX_CAUSES; $depth++) {
            $lines[] = ($depth === 0 ? '' : 'Caused by: ').self::type($e).': '.$e->getMessage();
            array_push($lines, ...self::frames($e));
            $e = $e->getPrevious();
        }

        return [
            'errorType' => self::type($error),
            'errorMessage' => $error->getMessage(),
            'errorStack' => implode("\n", $lines),
        ];
    }

    public static function type(Throwable $error): string
    {
        $class = $error::class;
        // Anonymous classes: "Foo@anonymous\0/path/to/file.php:12$0".
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
    }

    /** @return list<string> */
    private static function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        $out = [];
        // Frame i ran function trace[i] at the location where trace[i-1] was
        // called (or where the exception was thrown, for i = 0).
        $file = $e->getFile();
        $line = $e->getLine();
        foreach ($trace as $frame) {
            if (count($out) >= self::MAX_FRAMES) {
                break;
            }
            $fn = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
            $out[] = '    at '.$fn.' ('.self::location($file, $line).')';
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
        }
        if (count($out) < self::MAX_FRAMES && $file !== '') {
            $out[] = '    at {main} ('.self::location($file, $line).')';
        }

        return $out;
    }

    private static function location(string $file, int $line): string
    {
        if ($file === '') {
            return '[internal]';
        }

        return $line > 0 ? $file.':'.$line : $file;
    }
}
