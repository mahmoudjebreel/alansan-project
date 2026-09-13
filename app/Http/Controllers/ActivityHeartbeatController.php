<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Activity\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A visible panel tab reporting that somebody is still on the page.
 *
 * Deliberately not behind an auth middleware, for the reason the keep-alive
 * gives: the `auth` alias answers an expired session with a redirect to a
 * route name this application does not define. The check is made here
 * instead, and an expired session gets a plain 401 that the script reads as
 * "stop". Only a visit that belongs to the signed-in user can be touched; any
 * other token is ignored without comment, so the endpoint reveals nothing.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 *
 * @see resources/views/filament/scripts/activity-tracker.blade.php
 */
class ActivityHeartbeatController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->noContent(401);
        }

        ActivityRecorder::heartbeat(
            $user,
            (string) $request->input('visit', ''),
            (int) $request->input('seconds', 0),
        );

        return response()->noContent();
    }
}
