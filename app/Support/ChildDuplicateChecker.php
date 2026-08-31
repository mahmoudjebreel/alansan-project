<?php

namespace App\Support;

use App\Models\Child;
use Illuminate\Database\Eloquent\Model;

/**
 * Central point for deciding whether a child ID already exists in the system.
 *
 * Every lookup here relies on the model's default Eloquent scope, which the
 * SoftDeletes trait already narrows to non-trashed rows. A child that was
 * deleted (soft or hard) is therefore never treated as an existing record, so
 * re-registering the same ID afterwards behaves like a brand new child.
 */
class ChildDuplicateChecker
{
    /**
     * The most recent active (non-deleted) visit for the given child ID.
     */
    public static function latestActiveVisit(mixed $childId, ?Model $ignoreRecord = null): ?Child
    {
        if (blank($childId)) {
            return null;
        }

        return Child::query()
            ->where('child_id', $childId)
            ->when(
                $ignoreRecord instanceof Model && $ignoreRecord->exists,
                fn ($query) => $query->whereKeyNot($ignoreRecord->getKey()),
            )
            ->orderByDesc('date_of_reporting')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether an active record already exists for the given child ID.
     */
    public static function hasActiveVisit(mixed $childId, ?Model $ignoreRecord = null): bool
    {
        return static::latestActiveVisit($childId, $ignoreRecord) !== null;
    }

    /**
     * Nutritional severity ranking, from the mildest to the most severe case.
     *
     * This is the single place where an FI classification is turned into a
     * comparable number; nothing else may re-implement this ordering.
     */
    public const FI_SEVERITY = [
        'Normal' => 0,
        'MAM' => 1,
        'SAM' => 2,
    ];

    /**
     * Rank an FI classification. Returns null for an unknown/absent value.
     */
    public static function fiSeverity(?string $fi): ?int
    {
        return static::FI_SEVERITY[$fi] ?? null;
    }

    /**
     * Decide the visit type for a record being registered.
     *
     * 1. No active (non-deleted) record with the same child ID -> "new"; this
     *    is the child's first visit.
     * 2. An active record exists -> compare the nutritional severity of this
     *    visit against the last active one. Any deterioration (a higher
     *    severity) counts as a relapse and therefore a new admission; a stable
     *    or improved reading stays inside the same follow-up loop.
     *
     * The comparison uses the FI classification produced by the existing MUAC
     * classifier, which itself stays untouched.
     */
    public static function resolveVisitType(mixed $childId, mixed $currentMuacMm = null, ?Model $ignoreRecord = null): string
    {
        $previous = static::latestActiveVisit($childId, $ignoreRecord);

        if (! $previous) {
            return 'new';
        }

        $previousSeverity = static::fiSeverity($previous->fi);
        $currentSeverity = static::fiSeverity(Child::classifyMuac($currentMuacMm));

        // Nothing to compare yet (e.g. MUAC not entered): the visit belongs to
        // the existing follow-up loop until a measurement says otherwise.
        if ($previousSeverity === null || $currentSeverity === null) {
            return 'follow_up';
        }

        return $currentSeverity > $previousSeverity ? 'new' : 'follow_up';
    }
}
