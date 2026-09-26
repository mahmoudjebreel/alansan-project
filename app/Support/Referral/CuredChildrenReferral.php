<?php

namespace App\Support\Referral;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Support\ChildFollowUpTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cured follow-up episodes whose cure has not been written back to Children.
 *
 * The interactive discharge on the Follow Up Child edit page closes the
 * episode as cured AND writes the child back to Children in the same step.
 * Closing the episode any other way - the outcome picked on the form, or the
 * Excel import - writes the cured outcome only. This class finds those
 * episodes and lets a person send each one to Children by hand, through the
 * same transfer the interactive discharge already uses.
 *
 * The test is per episode, not per child. A Children row written by the
 * transfer carries source_follow_up_child_id, so "already referred" means a
 * live Children row points at THIS episode. The screening row that opened
 * the episode - or any other Children visit the same child has - is not a
 * referral of it, and never hides it. Two cured episodes for one child are
 * two referrals.
 *
 * Detection only, plus one deliberate write. Nothing here touches the
 * follow-up record: not its outcome, not its discharge date, not a visit.
 */
final class CuredChildrenReferral
{
    /** The record is not closed as cured. */
    public const BLOCKER_NOT_CURED = 'not_cured';

    /** A Children row was already written from this episode. */
    public const BLOCKER_ALREADY_EXISTS = 'already_exists';

    /** Name, ID number or sex is missing: Children will not store the row. */
    public const BLOCKER_MISSING_DATA = 'missing_data';

    /** No attended visit, so no final measurement to hand over. */
    public const BLOCKER_NO_VISIT = 'no_visit';

    /**
     * The child is recorded as died and the record would be dated after the
     * death. A dead child is not returned to the Children module.
     */
    public const BLOCKER_DIED = 'died';

    /**
     * Cured follow-up records no Children row was written from.
     *
     * The ID number must be present because Children stores the child under
     * it; it is not the key of the lookup. A Children row in the trash does
     * not count as written, which is how every other cross-module lookup in
     * the panel already reads soft-deleted rows.
     */
    public static function query(): Builder
    {
        return static::scope(FollowUpChild::query());
    }

    /**
     * Narrow any Follow Up Child query to the pending set above.
     */
    public static function scope(Builder $query): Builder
    {
        return $query
            ->where('discharge_outcome', FollowUpChild::CURED_OUTCOME)
            ->whereNotNull('id_number')
            ->where('id_number', '<>', '')
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $children): void {
                $children->select(DB::raw(1))
                    ->from('children')
                    ->whereColumn('children.source_follow_up_child_id', 'follow_up_children.id')
                    ->whereNull('children.deleted_at');
            });
    }

    /**
     * Whether a live Children row was already written from this episode.
     */
    public static function isTransferred(FollowUpChild $record): bool
    {
        return Child::query()
            ->where('source_follow_up_child_id', $record->getKey())
            ->exists();
    }

    /**
     * Whether this record is a cured episode still waiting to be sent to
     * Children: cured, carrying an ID number, and not yet written across.
     */
    public static function isPending(FollowUpChild $record): bool
    {
        return $record->discharge_outcome === FollowUpChild::CURED_OUTCOME
            && filled($record->id_number)
            && ! static::isTransferred($record);
    }

    /**
     * Why the record cannot be referred right now, or null when it can.
     *
     * Checked in the order a person would ask: is it cured, was it already
     * sent, does Children have what it needs, is there a reading to carry.
     */
    public static function blocker(FollowUpChild $record): ?string
    {
        if ($record->discharge_outcome !== FollowUpChild::CURED_OUTCOME) {
            return self::BLOCKER_NOT_CURED;
        }

        if (blank($record->id_number) || static::isTransferred($record)) {
            return self::BLOCKER_ALREADY_EXISTS;
        }

        if (! ChildFollowUpTransfer::canDischargeToChildren($record)) {
            return self::BLOCKER_MISSING_DATA;
        }

        $latestVisit = $record->latestAttendedVisit();

        if ($latestVisit === null) {
            return self::BLOCKER_NO_VISIT;
        }

        if (ChildFollowUpTransfer::refusesDischargeToChildren($record, $latestVisit) !== null) {
            return self::BLOCKER_DIED;
        }

        return null;
    }

    /**
     * Send the cured child to Children, or return null and write nothing.
     *
     * The episode is re-checked against Children at the moment of the
     * write - not when the list was drawn - so a row written from it in
     * between, by another person or another tab, is found and no duplicate
     * is made. The Children row itself is written by the existing discharge transfer,
     * carrying the child's last attended reading, exactly as the interactive
     * discharge does. The follow-up record is only read.
     */
    public static function refer(FollowUpChild $record): ?Child
    {
        return DB::transaction(function () use ($record): ?Child {
            $record->refresh();

            if (static::blocker($record) !== null) {
                return null;
            }

            $latestVisit = $record->latestAttendedVisit();

            return ChildFollowUpTransfer::discharge($record, $latestVisit);
        });
    }
}
