<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Keeps an open form's session alive.
 *
 * The data-entry forms in this system are long - the Children form alone has
 * sixty-eight fields across four tabs - and are routinely left open while the
 * screener goes and finds a missing answer. Laravel's session expires after
 * SESSION_LIFETIME minutes of no requests, and the next Livewire round trip
 * then comes back 419: "This page has expired", losing everything typed.
 *
 * The panel pings this while a tab is open and visible. Touching the session
 * is the whole job - the web middleware group reading it is what pushes the
 * expiry forward - so the response is deliberately empty.
 *
 * Deliberately not behind `auth`: it reads nothing and returns nothing, and the
 * auth middleware would answer an already-expired session with a redirect to a
 * route name this application does not define, turning the very case this
 * exists for into a 500.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 *
 * @see resources/views/filament/scripts/dashboard-alerts.blade.php
 */
class SessionKeepAliveController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
