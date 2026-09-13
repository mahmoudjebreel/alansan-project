<?php

namespace App\Http\Middleware;

use App\Support\Activity\ActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes each full page load inside the panel, after the page has been sent.
 *
 * On the way in it does one thing only: mint a token for the visit and attach
 * it to the request, so the tracker script rendered into the page knows what
 * to report back as. Nothing is written until terminate(), which Laravel runs
 * once the response has already gone out - so the write can neither slow the
 * page nor, should it fail, break it. The recorder catches its own errors.
 *
 * Laravel resolves a fresh instance of this class for terminate(), so the
 * token travels on the request attributes rather than on the object.
 */
class TrackUserActivity
{
    public const TOKEN_ATTRIBUTE = 'user_activity.visit_token';

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('user-activity.enabled', true)) {
            $request->attributes->set(self::TOKEN_ATTRIBUTE, (string) Str::uuid());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);

        ActivityRecorder::recordRequest($request, $response, is_string($token) ? $token : null);
    }
}
