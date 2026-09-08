<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A serverless host keeps only the last few kilobytes of what a
        // request writes to the log. Laravel's own report runs far past that -
        // the message followed by forty-odd framework frames with every
        // argument spelled out - so what gets dropped is the head of it, which
        // is the only part naming what broke. What survives reads identically
        // for every error in the application, and a 500 cannot be traced back.
        //
        // This replaces that report with one short enough to arrive whole: the
        // exception and everything it was caused by, then the frames, with the
        // application's own listed before the framework's because that is
        // where a fix starts. Arguments are dropped - they are most of the
        // length, and the file and line already say where to look.
        $exceptions->report(function (Throwable $e): void {
            try {
                $lines = [];

                for ($cause = $e, $depth = 0; $cause !== null && $depth < 4; $cause = $cause->getPrevious(), $depth++) {
                    $lines[] = sprintf(
                        '%s%s: %s @ %s:%d',
                        $depth === 0 ? '' : 'caused by ',
                        $cause::class,
                        $cause->getMessage(),
                        $cause->getFile(),
                        $cause->getLine(),
                    );
                }

                $frames = [];

                foreach ($e->getTrace() as $frame) {
                    $file = strtr($frame['file'] ?? '[internal]', DIRECTORY_SEPARATOR, '/');

                    $frames[str_contains($file, '/vendor/') ? 'vendor' : 'app'][] = sprintf(
                        '  %s:%s %s%s%s()',
                        $file,
                        $frame['line'] ?? '?',
                        $frame['class'] ?? '',
                        $frame['type'] ?? '',
                        $frame['function'] ?? '',
                    );
                }

                $report = implode(PHP_EOL, array_merge(
                    $lines,
                    array_slice($frames['app'] ?? [], 0, 12),
                    array_slice($frames['vendor'] ?? [], 0, 12),
                ));
            } catch (Throwable $formatting) {
                $report = $e::class.': '.$e->getMessage();
            }

            // Reporting must not become the failure it was called about. The
            // logger is unavailable while the application is still booting,
            // and by then this is the only report there is, so anything it
            // raises falls back to the error log the host is already reading.
            try {
                Log::error($report);
            } catch (Throwable $logging) {
                error_log($report);
            }
        })->stop();
    })->create();
