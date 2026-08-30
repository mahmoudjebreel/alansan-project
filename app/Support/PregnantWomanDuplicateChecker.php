<?php

namespace App\Support;

use App\Models\PregnantLactatingWoman;
use Illuminate\Database\Eloquent\Model;

/**
 * Central point for deciding whether a mother ID already exists in the system.
 *
 * Every lookup here relies on the model's default Eloquent scope, which the
 * SoftDeletes trait already narrows to non-trashed rows. A record that was
 * deleted (soft or hard) is therefore never treated as an existing record, so
 * re-registering the same mother ID afterwards behaves like a brand new entry.
 */
class PregnantWomanDuplicateChecker
{
    /**
     * The most recent active (non-deleted) visit for the given mother ID.
     */
    public static function latestActiveVisit(mixed $motherId, ?Model $ignoreRecord = null): ?PregnantLactatingWoman
    {
        if (blank($motherId)) {
            return null;
        }

        return PregnantLactatingWoman::query()
            ->where('mother_id', $motherId)
            ->when(
                $ignoreRecord instanceof Model && $ignoreRecord->exists,
                fn ($query) => $query->whereKeyNot($ignoreRecord->getKey()),
            )
            ->orderByDesc('date_of_reporting')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether an active record already exists for the given mother ID.
     */
    public static function hasActiveVisit(mixed $motherId, ?Model $ignoreRecord = null): bool
    {
        return static::latestActiveVisit($motherId, $ignoreRecord) !== null;
    }

    /**
     * Decide the visit type for a record being registered.
     *
     * 1. No active (non-deleted) record with the same mother ID -> "new"; this
     *    is her first visit.
     * 2. An active record exists -> compare this visit's pregnant/lactating
     *    status against the last active one. Switching between the two is an
     *    admission into a completely different care cycle and therefore a new
     *    entry; staying in the same status continues the follow-up loop.
     *
     * While the status of the current visit is still empty (the field is
     * deliberately left blank after prefilling from the alert), the visit stays
     * inside the existing follow-up loop until the user picks a status.
     */
    public static function resolveVisitType(mixed $motherId, mixed $currentStatusType = null, ?Model $ignoreRecord = null): string
    {
        $previous = static::latestActiveVisit($motherId, $ignoreRecord);

        if (! $previous) {
            return 'new';
        }

        if (blank($currentStatusType) || blank($previous->status_type)) {
            return 'follow_up';
        }

        return $currentStatusType !== $previous->status_type ? 'new' : 'follow_up';
    }
}
