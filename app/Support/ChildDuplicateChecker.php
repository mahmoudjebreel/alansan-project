<?php

namespace App\Support;

use App\Models\Child;
use App\Models\FollowUpChild;
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

        if ($previous) {
            return static::resolveVisitTypeFrom($previous, $currentMuacMm);
        }

        // No screening on file, but the child is known to the follow-up
        // module: an existing child, whatever became of that episode.
        $episode = static::latestFollowUpEpisode($childId);

        if ($episode) {
            return static::resolveVisitTypeAgainstFollowUp($episode, $currentMuacMm);
        }

        return 'new';
    }

    /**
     * Whether the child is known to the system at all - in Children, or in the
     * follow-up module under any outcome, open or closed.
     *
     * Identity is a matter of the ID alone. A closed follow-up episode is a
     * finished treatment, not a forgotten child: the row that carried it still
     * says who the child is.
     */
    public static function isKnownChild(mixed $childId, ?Model $ignoreRecord = null): bool
    {
        return static::hasActiveVisit($childId, $ignoreRecord)
            || static::latestFollowUpEpisode($childId) !== null;
    }

    /**
     * The most recent follow-up episode on file for the child ID, open or
     * closed, or null when the follow-up module has never seen the child.
     * Trashed episodes are not part of the system any more, exactly as
     * trashed Children rows are not.
     */
    public static function latestFollowUpEpisode(mixed $childId): ?FollowUpChild
    {
        if (blank($childId)) {
            return null;
        }

        return FollowUpChild::query()
            ->where('id_number', $childId)
            ->orderByDesc('admission_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The relapse rule, measured against the last reading the follow-up
     * module took when Children has none to offer.
     *
     * Same comparison as resolveVisitTypeFrom(): a deterioration from the
     * last attended follow-up reading is a relapse and a new admission, a
     * stable or improved one is a follow-up, and with nothing to compare -
     * an episode with no measured visit - the child is simply a follow-up,
     * because they are known.
     */
    public static function resolveVisitTypeAgainstFollowUp(FollowUpChild $episode, mixed $currentMuacMm = null): string
    {
        $lastReading = $episode->latestAttendedVisit()?->muac;

        $previousSeverity = static::fiSeverity(Child::classifyMuac($lastReading));
        $currentSeverity = static::fiSeverity(Child::classifyMuac($currentMuacMm));

        if ($previousSeverity === null || $currentSeverity === null) {
            return 'follow_up';
        }

        return $currentSeverity > $previousSeverity ? 'new' : 'follow_up';
    }

    /**
     * The same decision, for a caller that has already fetched the previous
     * visit.
     *
     * The duplicate-alert path looks the previous visit up to describe it in
     * the dialog and then has to settle the visit type from the very same row;
     * going back through resolveVisitType() ran that lookup a second time on
     * every blur of the child ID field, on the busiest screen in the system.
     */
    public static function resolveVisitTypeFrom(?Child $previous, mixed $currentMuacMm = null): string
    {
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
