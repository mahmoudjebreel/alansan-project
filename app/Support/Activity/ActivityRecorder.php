<?php

namespace App\Support\Activity;

use App\Models\User;
use App\Models\UserPageVisit;
use App\Models\UserSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The single write path for session and page-visit telemetry.
 *
 * Every public method runs after the thing it describes has already happened
 * - the login has succeeded, the response has been sent, the heartbeat has
 * arrived - and every one of them swallows its own failures. Monitoring must
 * never be the reason a request, a sign-in or a save appears to fail.
 */
final class ActivityRecorder
{
    /** Seconds between two writes of last_seen_at for the same session. */
    private const TOUCH_INTERVAL_SECONDS = 60;

    public static function enabled(): bool
    {
        return (bool) config('user-activity.enabled', true);
    }

    /**
     * A sign-in just succeeded: open a session row and remember it in the
     * Laravel session so later requests can find it.
     *
     * Runs before Filament regenerates the session id (data survives the
     * regeneration, the id does not), so the hash is filled in by the first
     * tracked request instead.
     */
    public static function startSession(User $user, Request $request, bool $remember = false, ?int $activityId = null): ?UserSession
    {
        if (! self::enabled()) {
            return null;
        }

        return self::guard(function () use ($user, $request, $remember, $activityId): UserSession {
            $session = self::createSessionRow($user, $request, $remember, $activityId);

            self::sessionStore($request)?->put(UserSession::SESSION_KEY, $session->getKey());

            return $session;
        });
    }

    /**
     * The session the request carries, or the application's session store
     * when the sign-in happened outside an HTTP request (a console command,
     * a test). Null when there is no session at all.
     */
    private static function sessionStore(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        if (app()->bound('session.store')) {
            $store = app('session.store');

            return $store instanceof Session ? $store : null;
        }

        return null;
    }

    /**
     * The session row for this request, creating one when the sign-in
     * predates the deploy or the row has since been pruned.
     */
    public static function currentSession(User $user, Request $request): ?UserSession
    {
        $store = self::sessionStore($request);

        if (! self::enabled() || $store === null) {
            return null;
        }

        return self::guard(function () use ($user, $store, $request): UserSession {
            $id = $store->get(UserSession::SESSION_KEY);
            $session = is_numeric($id) ? UserSession::query()->find((int) $id) : null;

            if (
                $session === null
                || (int) $session->user_id !== (int) $user->getKey()
                || $session->logout_at !== null
            ) {
                $session = self::createSessionRow($user, $request, false, null);
                $store->put(UserSession::SESSION_KEY, $session->getKey());
            }

            if ($session->session_hash === null) {
                $session->forceFill(['session_hash' => hash('sha256', $store->getId())])->save();
            }

            return $session;
        });
    }

    /** A sign-out: close the session row and any visit still open on it. */
    public static function endSession(User $user, Request $request): void
    {
        $store = self::sessionStore($request);

        if (! self::enabled() || $store === null) {
            return;
        }

        self::guard(function () use ($user, $store): void {
            $id = $store->get(UserSession::SESSION_KEY);

            if (! is_numeric($id)) {
                return;
            }

            $session = UserSession::query()
                ->whereKey((int) $id)
                ->where('user_id', $user->getKey())
                ->whereNull('logout_at')
                ->first();

            if ($session === null) {
                return;
            }

            $session->forceFill([
                'logout_at' => now(),
                'last_seen_at' => now(),
                'ended_reason' => UserSession::ENDED_LOGOUT,
            ])->save();

            $session->visits()->whereNull('left_at')->update(['left_at' => now()]);
        });
    }

    /**
     * Called from the panel middleware after the response has been sent.
     * Records one visit for a full page load; ignores everything else.
     */
    public static function recordRequest(Request $request, Response $response, ?string $visitToken): void
    {
        if (! self::enabled() || ! is_string($visitToken) || $visitToken === '') {
            return;
        }

        self::guard(function () use ($request, $response, $visitToken): void {
            $user = $request->user();

            if (! $user instanceof User) {
                return;
            }

            if (! self::isTrackablePageLoad($request, $response)) {
                return;
            }

            $session = self::currentSession($user, $request);

            if ($session === null) {
                return;
            }

            self::touch($session);

            $routeName = $request->route()?->getName();
            [$subjectType, $subjectId] = PageLabels::subjectFor($routeName, $request->route('record'));

            UserPageVisit::create([
                'user_session_id' => $session->getKey(),
                'user_id' => $user->getKey(),
                'visit_token' => $visitToken,
                'route_name' => $routeName !== null ? Str::limit($routeName, 191, '') : null,
                'path' => Str::limit('/' . ltrim($request->path(), '/'), 512, ''),
                'page_kind' => PageLabels::kindFor($routeName),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip_address' => self::ip($request),
                'entered_at' => now(),
            ]);
        });
    }

    /**
     * A visible tab reporting in. Adds at most the configured cap, whatever
     * the browser claims, and only to a visit the same user still has open.
     */
    public static function heartbeat(User $user, string $visitToken, int $seconds): void
    {
        if (! self::enabled()) {
            return;
        }

        self::guard(function () use ($user, $visitToken, $seconds): void {
            $visit = self::openVisit($user, $visitToken);

            if ($visit === null) {
                return;
            }

            $visit->increment('active_seconds', self::capIncrement($seconds), [
                'heartbeats' => $visit->heartbeats + 1,
                'last_heartbeat_at' => now(),
            ]);

            if ($visit->session !== null) {
                self::touch($visit->session);
            }
        });
    }

    /** The tab is being closed or navigated away from. */
    public static function leave(User $user, string $visitToken, int $seconds): void
    {
        if (! self::enabled()) {
            return;
        }

        self::guard(function () use ($user, $visitToken, $seconds): void {
            $visit = self::openVisit($user, $visitToken);

            if ($visit === null) {
                return;
            }

            $visit->increment('active_seconds', self::capIncrement($seconds), [
                'left_at' => now(),
            ]);

            if ($visit->session !== null) {
                self::touch($visit->session);
            }
        });
    }

    private static function openVisit(User $user, string $visitToken): ?UserPageVisit
    {
        if (! Str::isUuid($visitToken)) {
            return null;
        }

        return UserPageVisit::query()
            ->where('visit_token', $visitToken)
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->first();
    }

    private static function capIncrement(int $seconds): int
    {
        $cap = max(0, (int) config('user-activity.max_heartbeat_increment_seconds', 120));

        return max(0, min($seconds, $cap));
    }

    /** last_seen_at, written at most once a minute per session. */
    private static function touch(UserSession $session): void
    {
        $lastSeen = $session->last_seen_at;

        if ($lastSeen !== null && $lastSeen->gt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            return;
        }

        $session->forceFill(['last_seen_at' => now()])->save();
    }

    private static function isTrackablePageLoad(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // Livewire round trips, fetches and anything else that is not a
        // person opening a page.
        if ($request->expectsJson() || $request->ajax() || $request->hasHeader('X-Livewire')) {
            return false;
        }

        $routeName = $request->route()?->getName();

        return is_string($routeName) && str_starts_with($routeName, 'filament.');
    }

    private static function createSessionRow(User $user, Request $request, bool $remember, ?int $activityId): UserSession
    {
        $summary = UserAgentSummary::parse($request->userAgent());

        return UserSession::create([
            'user_id' => $user->getKey(),
            'ip_address' => self::ip($request),
            'user_agent' => self::userAgent($request),
            'browser' => $summary['browser'],
            'platform' => $summary['platform'],
            'device_type' => $summary['device_type'],
            'remember' => $remember,
            'login_at' => now(),
            'last_seen_at' => now(),
            'login_activity_id' => $activityId,
        ]);
    }

    public static function ip(Request $request): ?string
    {
        $ip = $request->ip();

        return is_string($ip) ? Str::limit($ip, 45, '') : null;
    }

    public static function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return is_string($agent) && $agent !== '' ? Str::limit($agent, 1000, '') : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private static function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('User activity could not be recorded: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return null;
        }
    }
}
