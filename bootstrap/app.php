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
        // Serverless hosts cap a single log line at a few kilobytes, and a
        // Laravel stack trace is long enough to blow past that. What gets cut
        // is the head of the line - the class, the message and the file the
        // fault came from, i.e. the only part that identifies it - leaving
        // nothing but framework frames that read the same for every error.
        //
        // So the identity is emitted first, on its own short line, and the
        // full trace still follows from the default handler underneath.
        $exceptions->report(function (Throwable $e): void {
            for ($cause = $e, $depth = 0; $cause !== null && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
                Log::error(sprintf(
                    '%s%s: %s @ %s:%d',
                    $depth === 0 ? '' : 'caused by ',
                    $cause::class,
                    $cause->getMessage(),
                    $cause->getFile(),
                    $cause->getLine(),
                ));
            }
        });
    })->create();
