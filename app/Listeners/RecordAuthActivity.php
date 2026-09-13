<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Activity\ActivityRecorder;
use App\Support\Activity\AuditEvents;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Sign-ins, sign-outs and failed sign-ins, from Laravel's own auth events.
 *
 * Filament's login page fires these through the session guard exactly as a
 * hand-written login would, so nothing in the page is touched. Each handler
 * runs after the guard has already done its work and cannot affect it; the
 * recorder and the audit writer both swallow their own failures.
 *
 * The methods are deliberately not named handle*(): Laravel's event
 * auto-discovery would otherwise register them a second time on top of the
 * explicit Event::listen() calls in UserActivityServiceProvider.
 */
class RecordAuthActivity
{
    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $activity = AuditEvents::login($user, (bool) $event->remember);

        ActivityRecorder::startSession($user, request(), (bool) $event->remember, $activity?->getKey());
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        ActivityRecorder::endSession($user, request());

        AuditEvents::logout($user);
    }

    /**
     * Only the attempted email is read from the credentials. The password
     * is in there too, and it stays there.
     */
    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $user = $event->user;

        AuditEvents::loginFailed(
            is_string($email) ? mb_substr($email, 0, 255) : null,
            $user instanceof User ? $user : null,
        );
    }
}
