<?php

namespace App\Support\Referral;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\ReferralBatch;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Illuminate\Support\Facades\Log;

/**
 * Turns a reviewed selection of referral candidates into follow-up episodes.
 *
 * The episode itself is not created here: that is ChildFollowUpTransfer's job
 * and has been since the Children form gained its referral prompt. What this
 * adds is the part a bulk decision needs and a single form save does not -
 * one lookup for the whole selection instead of one per child, a run that
 * carries on past a child it could not refer, and an answer that says why
 * each child was left alone.
 *
 * A child already known to the follow-up module is not a failure. It is one
 * of two ordinary outcomes - an episode is open, or an episode is closed -
 * and each is counted and reported as itself.
 *
 * Nothing here is ever reached by the Excel import. A referral is always a
 * person deciding, after the upload has already committed.
 */
final class ReferralProcessor
{
    /** A follow-up episode was opened for this child. */
    public const OUTCOME_REFERRED = 'referred';

    /** An episode is already open: referring again would count one as two. */
    public const OUTCOME_SKIPPED_ACTIVE = 'skipped_active';

    /**
     * Every episode on file is closed and the latest one ended as a default
     * or an eligible other exit. That child comes back as a readmission, and
     * a readmission is opened one child at a time with the Readmission
     * action, never in bulk. Nothing is re-opened.
     *
     * A child whose latest closed episode ended as cured or non-responded is
     * not skipped: the referral opens a new episode for them, and the
     * transfer classifies it (a relapse, a readmission after relapse, or a
     * new admission) exactly as it does for a Children screening.
     */
    public const OUTCOME_SKIPPED_CLOSED = 'skipped_closed';

    /**
     * The child's latest closed episode ended as died. The history is final
     * and the child is never admitted again.
     */
    public const OUTCOME_SKIPPED_DIED = 'skipped_died';

    /** The reading is not one the programme admits on, or there is none. */
    public const OUTCOME_SKIPPED_INELIGIBLE = 'skipped_ineligible';

    /** The write itself did not go through, and the child is still waiting. */
    public const OUTCOME_FAILED = 'failed';

    /** The three outcomes that are a decision not to refer, not an error. */
    public const SKIPPED_OUTCOMES = [
        self::OUTCOME_SKIPPED_ACTIVE,
        self::OUTCOME_SKIPPED_CLOSED,
        self::OUTCOME_SKIPPED_DIED,
        self::OUTCOME_SKIPPED_INELIGIBLE,
    ];

    /**
     * Refer the given children, skipping any that must not be referred.
     *
     * Idempotent by construction, and re-checked against the database at the
     * moment of the run rather than trusting whatever the screen was showing:
     * a child is skipped when the reading is not one the programme admits on,
     * when an episode is already open for their ID - including one opened
     * moments ago by this same run, which is what stops a double-click, a
     * replayed request or two rows for the same child in one upload from
     * opening two episodes - or when their only episodes are closed.
     *
     * The `skipped` total is kept for callers that only want "how many were
     * left alone"; the three counters beside it say why.
     *
     * @param  iterable<int|string>  $childRecordIds  primary keys of `children`
     * @return array{referred: int, skipped: int, skipped_active: int, skipped_closed: int, skipped_died: int, skipped_ineligible: int, failed: int}
     */
    public static function refer(
        iterable $childRecordIds,
        ?ReferralBatch $batch = null,
        ?User $actor = null,
    ): array {
        $ids = array_values(array_unique(array_filter(
            is_array($childRecordIds) ? $childRecordIds : iterator_to_array($childRecordIds),
            static fn (mixed $id): bool => filled($id),
        )));

        $result = [
            'referred' => 0,
            'skipped' => 0,
            'skipped_active' => 0,
            'skipped_closed' => 0,
            'skipped_died' => 0,
            'skipped_ineligible' => 0,
            'failed' => 0,
        ];

        if ($ids === []) {
            return $result;
        }

        $actor ??= auth()->user();

        // Every child ID whose history ended in a death, read once for the
        // whole run rather than once per child.
        $terminal = FollowUpChild::terminalEpisodes();

        // Chunked so a "select all" over a very large upload never loads the
        // whole selection into memory at once.
        foreach (array_chunk($ids, 500) as $chunk) {
            $children = Child::query()->whereKey($chunk)->get();

            // One query for the whole chunk. Doing this per child is the
            // shape that makes a five-figure referral run unusable.
            $states = ReferralCandidates::followUpStateForChildIds(
                $children->pluck('child_id')->all(),
            );

            foreach ($children as $child) {
                $outcome = static::referOne($child, $states, $terminal, $batch, $actor);

                $result[$outcome]++;

                if (in_array($outcome, self::SKIPPED_OUTCOMES, true)) {
                    $result['skipped']++;
                }
            }
        }

        if ($result['referred'] > 0) {
            // The counters above the table have just stopped being true.
            ReferralCandidates::forgetSummaries();
        }

        return $result;
    }

    /**
     * One child, and which of the outcomes it produced.
     *
     * The state map is the run's own memory of the follow-up module: read
     * once for the chunk, and updated as episodes open so that a second row
     * for the same child in the same selection is skipped rather than
     * duplicated.
     *
     * @param  array<string, string>  $states  updated in place as episodes open
     * @param  array<string, mixed>  $terminal  child IDs whose history ended in a death
     */
    private static function referOne(
        Child $child,
        array &$states,
        array $terminal,
        ?ReferralBatch $batch,
        ?User $actor,
    ): string {
        if (! MuacClassifier::isMalnourished(MuacClassifier::classify($child->muac_mm))) {
            return self::OUTCOME_SKIPPED_INELIGIBLE;
        }

        if (filled($child->child_id) && isset($terminal[(string) $child->child_id])) {
            static::logSkipped($child, self::OUTCOME_SKIPPED_DIED, $batch, $actor);

            return self::OUTCOME_SKIPPED_DIED;
        }

        $state = filled($child->child_id) ? ($states[$child->child_id] ?? null) : null;

        if ($state === ReferralCandidates::STATE_OPEN) {
            static::logSkipped($child, self::OUTCOME_SKIPPED_ACTIVE, $batch, $actor);

            return self::OUTCOME_SKIPPED_ACTIVE;
        }

        // A closed history is referred again only when its latest episode
        // does not call for a readmission: after a default or an eligible
        // other exit the child is readmitted one at a time with the
        // Readmission action, exactly as before. The test is the one that
        // action is offered by, so every closed child has exactly one way
        // back. How the new episode is classified is the transfer's decision.
        if ($state === ReferralCandidates::STATE_CLOSED
            && FollowUpChild::readmittableEpisodeFor($child->child_id) !== null) {
            static::logSkipped($child, self::OUTCOME_SKIPPED_CLOSED, $batch, $actor);

            return self::OUTCOME_SKIPPED_CLOSED;
        }

        try {
            // The existing transfer: it re-checks the open episode itself and
            // writes the record and its first visit inside one transaction.
            $followUpChild = ChildFollowUpTransfer::refer($child);
        } catch (\Throwable $e) {
            // One child that could not be referred is one child, not the run.
            Log::warning('Referral failed for child ' . $child->getKey() . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return self::OUTCOME_FAILED;
        }

        if (! $followUpChild instanceof FollowUpChild) {
            // The transfer's own open-episode re-check won the race. Nothing
            // was written, and the reason is the same one reported above.
            if (filled($child->child_id)) {
                $states[$child->child_id] = ReferralCandidates::STATE_OPEN;
            }

            static::logSkipped($child, self::OUTCOME_SKIPPED_ACTIVE, $batch, $actor);

            return self::OUTCOME_SKIPPED_ACTIVE;
        }

        if (filled($child->child_id)) {
            $states[$child->child_id] = ReferralCandidates::STATE_OPEN;
        }

        static::log($child, $followUpChild, $batch, $actor);

        return self::OUTCOME_REFERRED;
    }

    /**
     * Record the decision in the panel's existing activity log.
     *
     * The follow-up record logs its own creation through LogsActivity; this
     * adds what that entry cannot say on its own - that the record came from
     * a reviewed referral, which screening it came from, and which upload.
     */
    private static function log(
        Child $child,
        FollowUpChild $followUpChild,
        ?ReferralBatch $batch,
        ?User $actor,
    ): void {
        static::write(
            static fn () => activity('referral')
                ->performedOn($followUpChild)
                ->causedBy($actor)
                ->withProperties([
                    'child_record_id' => $child->getKey(),
                    'child_id' => $child->child_id,
                    'child_name' => $child->name,
                    'classification' => $followUpChild->admitted_with,
                    'referral_batch_id' => $batch?->getKey(),
                ])
                ->event('referred')
                ->log('Child referred to follow-up'),
        );
    }

    /**
     * Record a child the run deliberately left alone, and why.
     *
     * There is no record to perform this on - that is the point of the entry -
     * so the child is named in the properties instead. No lookup is done here
     * either; a bulk run must not pay a query per skipped child.
     */
    private static function logSkipped(
        Child $child,
        string $outcome,
        ?ReferralBatch $batch,
        ?User $actor,
    ): void {
        $reason = match ($outcome) {
            self::OUTCOME_SKIPPED_ACTIVE => 'Referral skipped: the child is already in an active follow-up',
            self::OUTCOME_SKIPPED_DIED => 'Referral refused: the latest follow-up episode ended with Died',
            default => 'Referral skipped: the child has a closed follow-up record',
        };

        static::write(
            static fn () => activity('referral')
                ->causedBy($actor)
                ->withProperties([
                    'child_record_id' => $child->getKey(),
                    'child_id' => $child->child_id,
                    'child_name' => $child->name,
                    'referral_batch_id' => $batch?->getKey(),
                ])
                ->event($outcome)
                ->log($reason),
        );
    }

    /**
     * An audit entry must never be the reason a referral appears to fail: by
     * the time one is written the decision is already taken and, for a
     * referral, already committed.
     */
    private static function write(callable $entry): void
    {
        try {
            $entry();
        } catch (\Throwable $e) {
            Log::warning('Referral activity could not be logged: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
