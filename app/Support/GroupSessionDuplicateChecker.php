<?php

namespace App\Support;

use App\Models\GroupSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Central point for deciding whether a participant ID number has already been
 * registered in the group session module.
 *
 * Every lookup relies on the model's default Eloquent scope, which the
 * SoftDeletes trait already narrows to non-trashed rows. An ID number that only
 * survives in the trash is therefore never treated as a duplicate, and can be
 * entered again exactly like a brand new participant.
 */
class GroupSessionDuplicateChecker
{
    /**
     * The most recent active (non-deleted) group session for the given ID number.
     */
    public static function latestActiveSession(mixed $idNumber, ?Model $ignoreRecord = null): ?GroupSession
    {
        if (blank($idNumber)) {
            return null;
        }

        return GroupSession::query()
            ->where('id_number', $idNumber)
            ->when(
                $ignoreRecord instanceof Model && $ignoreRecord->exists,
                fn ($query) => $query->whereKeyNot($ignoreRecord->getKey()),
            )
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether an active record already exists for the given ID number.
     */
    public static function hasActiveSession(mixed $idNumber, ?Model $ignoreRecord = null): bool
    {
        return static::latestActiveSession($idNumber, $ignoreRecord) !== null;
    }

    /**
     * Decide the visit type for a session being registered.
     *
     * Unlike the children module there is no MUAC / FI reading here, so there is
     * no severity to compare and therefore no relapse rule: the presence of an
     * active session for the same ID number is the whole decision. A first
     * (or post-deletion) registration is "new"; anything else is a follow up.
     */
    public static function resolveVisitType(mixed $idNumber, ?Model $ignoreRecord = null): string
    {
        return static::hasActiveSession($idNumber, $ignoreRecord) ? 'follow_up' : 'new';
    }
}
