<?php

namespace App\Support\Referral;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\ReferralBatch;
use App\Support\MuacClassifier;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which screened children the programme would admit, read straight off the
 * Children table.
 *
 * This is a detection layer and nothing more: it classifies nothing new and
 * decides nothing new. The cut-offs are MuacClassifier's, and "already being
 * followed up" is the same rule ChildFollowUpTransfer::hasOpenEpisode()
 * applies one child at a time - expressed here as one correlated subquery so
 * a hundred and fifty thousand children cost one query rather than a hundred
 * and fifty thousand.
 */
final class ReferralCandidates
{
    /**
     * Every child eligible for referral, optionally narrowed to one upload.
     *
     * Eligible means: a MUAC that classifies as SAM or MAM, and no follow-up
     * episode still open for that child ID.
     */
    public static function query(?ReferralBatch $batch = null): Builder
    {
        return static::scopeToBatch(static::malnourished(Child::query()), $batch)
            ->whereNotExists(static::openEpisodeSubquery());
    }

    /**
     * The headline figures the Referral Centre shows above the table: what the
     * upload contained, and how much of it needs a decision.
     *
     * One grouped query for the classification split rather than four counts.
     *
     * @return array{total: int, normal: int, mam: int, sam: int, unmeasured: int, eligible: int}
     */
    public static function summary(?ReferralBatch $batch = null): array
    {
        $counts = static::scopeToBatch(Child::query(), $batch)
            ->selectRaw(static::classificationCase() . ' as classification, count(*) as aggregate')
            ->groupBy('classification')
            ->pluck('aggregate', 'classification');

        $sam = (int) ($counts[MuacClassifier::SAM] ?? 0);
        $mam = (int) ($counts[MuacClassifier::MAM] ?? 0);
        $normal = (int) ($counts[MuacClassifier::NORMAL] ?? 0);
        $unmeasured = (int) ($counts['unmeasured'] ?? 0);

        return [
            'total' => $sam + $mam + $normal + $unmeasured,
            'normal' => $normal,
            'mam' => $mam,
            'sam' => $sam,
            'unmeasured' => $unmeasured,
            'eligible' => static::query($batch)->count(),
        ];
    }

    /**
     * The child IDs of the given children that already have an open episode.
     *
     * One query for the whole selection, so the referral run can skip them
     * without asking the database once per child.
     *
     * @param  array<int, string|null>  $childIds
     * @return array<string, true>  a set, keyed by child ID
     */
    public static function childIdsAlreadyUnderFollowUp(array $childIds): array
    {
        $childIds = array_values(array_filter(
            array_unique($childIds),
            static fn (mixed $id): bool => filled($id),
        ));

        if ($childIds === []) {
            return [];
        }

        $open = FollowUpChild::query()
            ->whereIn('id_number', $childIds)
            ->where(static::openEpisodeCondition(...))
            ->pluck('id_number');

        return array_fill_keys($open->all(), true);
    }

    /**
     * Restrict a Children query to one upload's primary-key window.
     *
     * A missing batch means "every child on file", which is what makes a
     * referral run possible after a batch row was never recorded - a failed
     * listener must not strand the candidates it would have pointed at.
     */
    public static function scopeToBatch(Builder $query, ?ReferralBatch $batch): Builder
    {
        if ($batch instanceof ReferralBatch) {
            $query->whereBetween(
                $query->getModel()->qualifyColumn('id'),
                [$batch->first_record_id, $batch->last_record_id],
            );
        }

        return $query;
    }

    /**
     * SAM or MAM, by the shared thresholds rather than by the stored FI
     * column: FI is derived from the measurement everywhere else too, and a
     * row written before the column existed still classifies correctly.
     */
    private static function malnourished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('muac_mm')
            ->where('muac_mm', '<', MuacClassifier::MAM_MAX_MM);
    }

    /**
     * The same SQL classification the summary groups by, spelled with the
     * same two cut-offs the classifier uses.
     */
    private static function classificationCase(): string
    {
        $sam = MuacClassifier::SAM_MAX_MM;
        $mam = MuacClassifier::MAM_MAX_MM;

        return "case
            when muac_mm is null then 'unmeasured'
            when muac_mm <= {$sam} then '" . MuacClassifier::SAM . "'
            when muac_mm < {$mam} then '" . MuacClassifier::MAM . "'
            else '" . MuacClassifier::NORMAL . "'
        end";
    }

    /**
     * "This child already has an episode that has not been closed", as a
     * correlated subquery against the child ID.
     *
     * Mirrors ChildFollowUpTransfer::hasOpenEpisode() exactly, including the
     * soft-delete filter that the Eloquent query there applies for free.
     */
    private static function openEpisodeSubquery(): \Closure
    {
        return static function (\Illuminate\Database\Query\Builder $query): void {
            $query->select(DB::raw(1))
                ->from('follow_up_children')
                ->whereColumn('follow_up_children.id_number', 'children.child_id')
                ->whereNull('follow_up_children.deleted_at')
                ->where(static::openEpisodeCondition(...));
        };
    }

    /**
     * The outcome half of the rule, shared by the subquery and the batch
     * lookup so the two can never drift apart.
     */
    private static function openEpisodeCondition(BuilderContract $query): void
    {
        $query->whereNull('discharge_outcome')
            ->orWhereNotIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
    }
}
