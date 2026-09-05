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
 * one lookup for the whole selection instead of one per child, and a run that
 * carries on past a child it could not refer instead of losing the rest.
 *
 * Nothing here is ever reached by the Excel import. A referral is always a
 * person deciding, after the upload has already committed.
 */
final class ReferralProcessor
{
    /**
     * Refer the given children, skipping any that must not be referred.
     *
     * Idempotent by construction. A child is skipped when the reading is not
     * one the programme admits on, or when an episode is already open for
     * their ID - including one opened moments ago by this same run, which is
     * what stops a double-click, a replayed request or two rows for the same
     * child in one upload from opening two episodes.
     *
     * @param  iterable<int|string>  $childRecordIds  primary keys of `children`
     * @return array{referred: int, skipped: int, failed: int}
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

        $result = ['referred' => 0, 'skipped' => 0, 'failed' => 0];

        if ($ids === []) {
            return $result;
        }

        $actor ??= auth()->user();

        // Chunked so a "select all" over a very large upload never loads the
        // whole selection into memory at once.
        foreach (array_chunk($ids, 500) as $chunk) {
            $children = Child::query()->whereKey($chunk)->get();

            // One query for the whole chunk. Doing this per child is the
            // shape that makes a five-figure referral run unusable.
            $underFollowUp = ReferralCandidates::childIdsAlreadyUnderFollowUp(
                $children->pluck('child_id')->all(),
            );

            foreach ($children as $child) {
                $outcome = static::referOne($child, $underFollowUp, $batch, $actor);

                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * One child, and which of the three outcomes it produced.
     *
     * @param  array<string, true>  $underFollowUp  updated in place as episodes open
     * @return 'referred'|'skipped'|'failed'
     */
    private static function referOne(
        Child $child,
        array &$underFollowUp,
        ?ReferralBatch $batch,
        ?User $actor,
    ): string {
        if (! MuacClassifier::isMalnourished(MuacClassifier::classify($child->muac_mm))) {
            return 'skipped';
        }

        if (filled($child->child_id) && isset($underFollowUp[$child->child_id])) {
            return 'skipped';
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

            return 'failed';
        }

        if (! $followUpChild instanceof FollowUpChild) {
            return 'skipped';
        }

        if (filled($child->child_id)) {
            $underFollowUp[$child->child_id] = true;
        }

        static::log($child, $followUpChild, $batch, $actor);

        return 'referred';
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
        try {
            activity('referral')
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
                ->log('Child referred to follow-up');
        } catch (\Throwable $e) {
            // An audit entry must never be the reason a referral appears to
            // fail: the episode is already committed by this point.
            Log::warning('Referral activity could not be logged: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
