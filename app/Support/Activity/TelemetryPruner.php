<?php

namespace App\Support\Activity;

use App\Models\UserPageVisit;
use App\Models\UserSession;

/**
 * Deletes page-visit and session telemetry older than the retention period.
 *
 * Only the two telemetry tables are touched. Nothing here reads or writes
 * activity_log: the login, logout, export and backup rows there are audit
 * records and are kept like every other row in that table.
 */
final class TelemetryPruner
{
    private const CHUNK = 2000;

    /**
     * @return array{visits: int, sessions: int, expired: int}
     */
    public static function prune(?int $days = null): array
    {
        $days = max(1, $days ?? (int) config('user-activity.retention_days', 90));
        $cutoff = now()->subDays($days);

        // Sessions that went quiet and were never signed out are stamped so
        // the listing does not have to work it out from the timestamps.
        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        $expired = UserSession::query()
            ->whereNull('logout_at')
            ->whereNull('ended_reason')
            ->where('last_seen_at', '<', now()->subMinutes($lifetimeMinutes))
            ->update(['ended_reason' => UserSession::ENDED_EXPIRED]);

        $visits = self::deleteInChunks(
            UserPageVisit::query()->where('entered_at', '<', $cutoff),
        );

        // A session older than the cut-off that still has visits is one that
        // is still in use, or was until recently; it stays until its visits go.
        $sessions = self::deleteInChunks(
            UserSession::query()
                ->where('login_at', '<', $cutoff)
                ->whereDoesntHave('visits'),
        );

        return ['visits' => $visits, 'sessions' => $sessions, 'expired' => $expired];
    }

    private static function deleteInChunks(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $model = $query->getModel();
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck($model->getKeyName())->all();

            if ($ids === []) {
                break;
            }

            $deleted += $model->newQuery()->whereKey($ids)->delete();
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }
}
