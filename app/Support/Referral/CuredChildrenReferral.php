<?php

namespace App\Support\Referral;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Support\ChildFollowUpTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cured follow-up children who are missing from the Children module.
 *
 * The interactive discharge on the Follow Up Child edit page closes the
 * episode as cured AND writes the child back to Children in the same step.
 * The Excel import writes the cured outcome only, so a cured child that
 * arrived by upload may have no Children row at all. This class finds those
 * cases by ID number and lets a person send each one to Children by hand,
 * through the same transfer the interactive discharge already uses.
 *
 * Detection only, plus one deliberate write. Nothing here touches the
 * follow-up record: not its outcome, not its discharge date, not a visit.
 */
final class CuredChildrenReferral
{
    /** The record is not closed as cured. */
    public const BLOCKER_NOT_CURED = 'not_cured';

    /** A Children row already carries this ID number. */
    public const BLOCKER_ALREADY_EXISTS = 'already_exists';

    /** Name, ID number or sex is missing: Children will not store the row. */
    public const BLOCKER_MISSING_DATA = 'missing_data';

    /** No attended visit, so no final measurement to hand over. */
    public const BLOCKER_NO_VISIT = 'no_visit';

    /**
     * Cured follow-up records whose ID number appears on no Children row.
     *
     * The ID number is the identity key; the name plays no part. A Children
     * row in the trash does not count as present, which is how every other
     * cross-module lookup in the panel already reads soft-deleted rows.
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
                    ->whereColumn('children.child_id', 'follow_up_children.id_number')
                    ->whereNull('children.deleted_at');
            });
    }

    /**
     * Whether a Children row exists for this ID number.
     */
    public static function existsInChildren(mixed $idNumber): bool
    {
        if (blank($idNumber)) {
            return false;
        }

        return Child::query()->where('child_id', $idNumber)->exists();
    }

    /**
     * Whether this record is a cured child still waiting to be sent to
     * Children: cured, carrying an ID number, and unknown to Children.
     */
    public static function isPending(FollowUpChild $record): bool
    {
        return $record->discharge_outcome === FollowUpChild::CURED_OUTCOME
            && filled($record->id_number)
            && ! static::existsInChildren($record->id_number);
    }

    /**
     * Why the record cannot be referred right now, or null when it can.
     *
     * Checked in the order a person would ask: is it cured, is it already
     * there, does Children have what it needs, is there a reading to carry.
     */
    public static function blocker(FollowUpChild $record): ?string
    {
        if ($record->discharge_outcome !== FollowUpChild::CURED_OUTCOME) {
            return self::BLOCKER_NOT_CURED;
        }

        if (blank($record->id_number) || static::existsInChildren($record->id_number)) {
            return self::BLOCKER_ALREADY_EXISTS;
        }

        if (! ChildFollowUpTransfer::canDischargeToChildren($record)) {
            return self::BLOCKER_MISSING_DATA;
        }

        if ($record->latestAttendedVisit() === null) {
            return self::BLOCKER_NO_VISIT;
        }

        return null;
    }

    /**
     * Send the cured child to Children, or return null and write nothing.
     *
     * The ID number is re-checked against Children at the moment of the
     * write - not when the list was drawn - so a Children row created in
     * between, by an import or by hand, is found and no duplicate is made.
     * The Children row itself is written by the existing discharge transfer,
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
