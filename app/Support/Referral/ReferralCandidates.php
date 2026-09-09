<?php

namespace App\Support\Referral;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\ReferralBatch;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
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
     * SAM or MAM, no follow-up episode of any kind on file. The child is
     * waiting for somebody to decide.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * SAM or MAM, and every episode on file is closed.
     *
     * Listed but never referred. A closed episode is a finished treatment
     * history, and re-referring the child would silently start a second one;
     * the row is shown with its history reachable so a person can decide what
     * the case actually needs. Nothing here re-opens anything.
     *
     * @see \App\Models\FollowUpChild::CLOSING_OUTCOMES
     */
    public const STATUS_PREVIOUSLY_FOLLOWED = 'previously_followed';

    /**
     * An episode is open for this child ID. Referring again would count one
     * episode as two, so the row is shown and not offered.
     */
    public const STATUS_IN_FOLLOW_UP = 'in_follow_up';

    /**
     * No usable MUAC. Deliberately its own state: a blank measurement is not
     * Normal, not MAM and not SAM, and nothing here may guess which.
     */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * Not a state but the default view: everything a person may still act on,
     * which is exactly what query() has always returned.
     */
    public const FILTER_ELIGIBLE = 'eligible';

    /** Every state, in the order the Referral Centre lists them. */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PREVIOUSLY_FOLLOWED,
        self::STATUS_IN_FOLLOW_UP,
        self::STATUS_NEEDS_REVIEW,
    ];

    /**
     * The two states an existing follow-up record can be in, as
     * followUpStateForChildIds() reports them. Not statuses: a status is
     * about a screened child, these are about the episode on file.
     */
    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    /** Cache key holding the stamp that invalidates every cached summary. */
    private const GENERATION_KEY = 'referral:summary:generation';

    /** How long a set of counters may be reused before it is recomputed. */
    private const SUMMARY_TTL_SECONDS = 60;

    /**
     * Every child eligible for referral, optionally narrowed to one upload.
     *
     * Eligible means: a MUAC that classifies as SAM or MAM, and no follow-up
     * episode of any kind on file for that child ID - neither an open one,
     * which referring again would count twice, nor a closed one, which
     * referring again would re-admit behind a finished treatment history.
     *
     * This is exactly STATUS_PENDING; the two are kept as separate entry
     * points because one is the referable set and the other is the label.
     */
    public static function query(?ReferralBatch $batch = null): Builder
    {
        return static::scopeToBatch(static::malnourished(Child::query()), $batch)
            ->whereNotExists(static::openEpisodeSubquery())
            ->whereNotExists(static::closedEpisodeSubquery());
    }

    /**
     * Every child the Referral Centre has anything to say about: the SAM and
     * MAM readings, plus the ones carrying no measurement at all.
     *
     * A Normal reading is a finished screening and appears nowhere here.
     * Nothing about eligibility is decided at this level - that is what the
     * status is for - so this is only the outer bound of the listing.
     */
    public static function overview(): Builder
    {
        return Child::query()->where(function (Builder $query): void {
            $query->whereNull('muac_mm')
                ->orWhere('muac_mm', '<', MuacClassifier::MAM_MAX_MM);
        });
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
     * Narrow a listing to one referral status.
     *
     * A null status leaves the query alone, which is what the "everything"
     * option on the page passes.
     */
    public static function scopeToStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::FILTER_ELIGIBLE => static::malnourished($query)
                ->whereNotExists(static::openEpisodeSubquery())
                ->whereNotExists(static::closedEpisodeSubquery()),

            self::STATUS_PENDING => static::malnourished($query)
                ->whereNotExists(static::openEpisodeSubquery())
                ->whereNotExists(static::closedEpisodeSubquery()),

            self::STATUS_PREVIOUSLY_FOLLOWED => static::malnourished($query)
                ->whereNotExists(static::openEpisodeSubquery())
                ->whereExists(static::closedEpisodeSubquery()),

            self::STATUS_IN_FOLLOW_UP => $query->whereExists(static::openEpisodeSubquery()),

            self::STATUS_NEEDS_REVIEW => $query
                ->whereNull('muac_mm')
                ->whereNotExists(static::openEpisodeSubquery()),

            default => $query,
        };
    }

    /**
     * The status of one already-loaded child, without going back to the
     * database for it in a loop.
     *
     * The table selects the same answer in SQL for a whole page at once; this
     * is the fallback for a row that arrived by another route, and the single
     * definition the labels and badge colours are read from.
     */
    public static function statusFor(Child $child): string
    {
        if (filled($child->child_id) && ChildFollowUpTransfer::hasOpenEpisode($child->child_id)) {
            return self::STATUS_IN_FOLLOW_UP;
        }

        if (blank($child->muac_mm)) {
            return self::STATUS_NEEDS_REVIEW;
        }

        $previouslyFollowed = filled($child->child_id) && FollowUpChild::query()
            ->where('id_number', $child->child_id)
            ->whereIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES)
            ->exists();

        return $previouslyFollowed ? self::STATUS_PREVIOUSLY_FOLLOWED : self::STATUS_PENDING;
    }

    /**
     * The same decision expressed in SQL, so a page of rows costs one query
     * rather than one query per row.
     *
     * The order of the arms is the precedence: an open episode outranks
     * everything, and a missing measurement is answered before any attempt is
     * made to classify one.
     */
    public static function statusCase(): string
    {
        return 'case'
            . ' when ' . static::existsSql('fu_open', static::openEpisodeSql('fu_open'))
            . " then '" . self::STATUS_IN_FOLLOW_UP . "'"
            . " when children.muac_mm is null then '" . self::STATUS_NEEDS_REVIEW . "'"
            . ' when ' . static::existsSql('fu_closed', static::closedEpisodeSql('fu_closed'))
            . " then '" . self::STATUS_PREVIOUSLY_FOLLOWED . "'"
            . " else '" . self::STATUS_PENDING . "'"
            . ' end';
    }

    /**
     * How many children sit in each status, plus the follow-up figures the
     * same screen reports.
     *
     * Cached briefly and stamped, so a referral run that has just changed the
     * answer does not leave a stale number on the screen behind it.
     *
     * @return array{pending: int, previously_followed: int, in_follow_up: int, needs_review: int, active_follow_ups: int, closed_cases: int, missing_follow_up_muac: int}
     */
    public static function statusSummary(?ReferralBatch $batch = null): array
    {
        $key = self::GENERATION_KEY . ':' . static::generation() . ':' . ($batch?->getKey() ?? 'all');

        return Cache::remember(
            $key,
            self::SUMMARY_TTL_SECONDS,
            static fn (): array => static::computeStatusSummary($batch),
        );
    }

    /**
     * Discard every cached counter. Called after anything that moves a child
     * between the states above.
     */
    public static function forgetSummaries(): void
    {
        Cache::put(self::GENERATION_KEY, static::generation() + 1);
    }

    /**
     * Follow-up episodes that have not been closed.
     */
    public static function activeFollowUps(): Builder
    {
        return FollowUpChild::query()->where(static::openEpisodeCondition(...));
    }

    /**
     * Follow-up episodes closed by one of the recorded discharge outcomes.
     *
     * No new outcome is invented here: the list is the model's own.
     */
    public static function closedFollowUps(): Builder
    {
        return FollowUpChild::query()->whereIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
    }

    /**
     * Recorded visits carrying no measurement.
     *
     * The visit is never deleted and no value is ever supplied for it; the row
     * is listed so somebody can go and find the reading.
     */
    public static function visitsMissingMuac(): Builder
    {
        return FollowUpChildVisit::query()
            ->whereNull('muac')
            // A missed visit has no reading because nobody was there to
            // take one; it is not a measurement waiting to be found.
            ->where('status', FollowUpChildVisit::STATUS_ATTENDED)
            ->whereHas('followUpChild');
    }

    /**
     * Where each of the given child IDs already stands in the follow-up
     * module: an open episode, a closed one, or nothing on file.
     *
     * One query for the whole selection, so a bulk referral asks the database
     * once rather than once per child. An open episode outranks a closed one -
     * the same precedence statusCase() applies - so a child with a finished
     * episode and a current one reads as open.
     *
     * @param  array<int, string|null>  $childIds
     * @return array<string, string>  child ID => STATE_OPEN|STATE_CLOSED
     */
    public static function followUpStateForChildIds(array $childIds): array
    {
        $childIds = array_values(array_filter(
            array_unique($childIds),
            static fn (mixed $id): bool => filled($id),
        ));

        if ($childIds === []) {
            return [];
        }

        $states = [];

        FollowUpChild::query()
            ->whereIn('id_number', $childIds)
            ->select('id_number', 'discharge_outcome')
            ->get()
            ->each(function (FollowUpChild $episode) use (&$states): void {
                $state = in_array($episode->discharge_outcome, FollowUpChild::CLOSING_OUTCOMES, true)
                    ? self::STATE_CLOSED
                    : self::STATE_OPEN;

                // Open wins: a returning child with one finished episode and
                // one current episode is being treated, not discharged.
                if ($state === self::STATE_OPEN || ! isset($states[$episode->id_number])) {
                    $states[$episode->id_number] = $state;
                }
            });

        return $states;
    }

    /**
     * The follow-up record a listed child's history should open at, as a
     * scalar subquery.
     *
     * Selected alongside the row so the "view follow-up" action costs no
     * lookup of its own. The open episode wins, and the most recent one after
     * that, which is the record a person reviewing the case wants first.
     */
    public static function followUpChildIdSql(): string
    {
        return '(select fu_link.id from follow_up_children fu_link'
            . ' where fu_link.id_number = children.child_id'
            . ' and fu_link.deleted_at is null'
            . ' order by case when ' . static::openEpisodeSql('fu_link') . ' then 0 else 1 end,'
            . ' fu_link.id desc limit 1)';
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
     * @return array{pending: int, previously_followed: int, in_follow_up: int, needs_review: int, active_follow_ups: int, closed_cases: int, missing_follow_up_muac: int}
     */
    private static function computeStatusSummary(?ReferralBatch $batch): array
    {
        $counts = static::scopeToBatch(static::overview(), $batch)
            ->selectRaw(static::statusCase() . ' as referral_status, count(*) as aggregate')
            ->groupBy('referral_status')
            ->pluck('aggregate', 'referral_status');

        $summary = [];

        foreach (self::STATUSES as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary + [
            'active_follow_ups' => static::activeFollowUps()->count(),
            'closed_cases' => static::closedFollowUps()->count(),
            'missing_follow_up_muac' => static::visitsMissingMuac()->count(),
        ];
    }

    private static function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 0);
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
     * "This child ID has a closed episode on file", as a correlated subquery.
     * The mirror image of openEpisodeSubquery().
     */
    private static function closedEpisodeSubquery(): \Closure
    {
        return static function (\Illuminate\Database\Query\Builder $query): void {
            $query->select(DB::raw(1))
                ->from('follow_up_children')
                ->whereColumn('follow_up_children.id_number', 'children.child_id')
                ->whereNull('follow_up_children.deleted_at')
                ->whereIn('follow_up_children.discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
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

    /**
     * The open-episode test as literal SQL, for the CASE expression.
     */
    private static function openEpisodeSql(string $alias): string
    {
        return "({$alias}.discharge_outcome is null"
            . " or {$alias}.discharge_outcome not in (" . static::quotedClosingOutcomes() . '))';
    }

    private static function closedEpisodeSql(string $alias): string
    {
        return "{$alias}.discharge_outcome in (" . static::quotedClosingOutcomes() . ')';
    }

    private static function existsSql(string $alias, string $condition): string
    {
        return "exists (select 1 from follow_up_children {$alias}"
            . " where {$alias}.id_number = children.child_id"
            . " and {$alias}.deleted_at is null"
            . " and {$condition})";
    }

    /**
     * The closing outcomes as SQL string literals.
     *
     * They are class constants and never user input, and are quoted here all
     * the same so the expression cannot become a place where something else
     * could be.
     */
    private static function quotedClosingOutcomes(): string
    {
        return implode(', ', array_map(
            static fn (string $outcome): string => "'" . str_replace("'", "''", $outcome) . "'",
            FollowUpChild::CLOSING_OUTCOMES,
        ));
    }
}
