<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Activity\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A panel tab being closed or navigated away from, sent as a beacon.
 *
 * Same access rule as the heartbeat: the signed-in user's own open visit, or
 * nothing happens. Browsers do not promise to deliver a beacon, so a visit
 * that never receives one is closed by its last heartbeat instead.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 */
class ActivityPageLeaveController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->noContent(401);
        }

        ActivityRecorder::leave(
            $user,
            (string) $request->input('visit', ''),
            (int) $request->input('seconds', 0),
        );

        return response()->noContent();
    }
}
