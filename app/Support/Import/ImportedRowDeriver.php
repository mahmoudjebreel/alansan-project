<?php

namespace App\Support\Import;

use App\Support\ChildDuplicateChecker;
use App\Support\GroupSessionDuplicateChecker;
use App\Support\PregnantWomanDuplicateChecker;
use Carbon\Carbon;

/**
 * Recompute, for an uploaded row, the columns the system decides for itself.
 *
 * The manual Create form locks these fields and derives them server-side, so a
 * bulk upload was the one door through which a hand-typed visit type or age
 * could reach the database - a file saying "new" for a child already under
 * follow-up simply overwrote the relapse rule, and nothing said so.
 *
 * Each module names one of the methods here through its import definition. The
 * value in the file is read and then discarded: whatever the sheet says, the
 * stored value is the one the rules produce.
 *
 * Note the scope deliberately: a row is derived against the records that
 * existed before the file was opened. Two rows for the same person inside one
 * file therefore both compare against the same prior state, which is the same
 * answer the module's own duplicate alert would have given for each of them
 * entered separately on the same day.
 *
 * @see \App\Imports\ImportDefinition::$deriver
 */
final class ImportedRowDeriver
{
    /**
     * Children: the visit type comes from the relapse rule, and the age in
     * months from the date of birth.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function children(array $attributes): array
    {
        $attributes['visit_type'] = ChildDuplicateChecker::resolveVisitType(
            $attributes['child_id'] ?? null,
            $attributes['muac_mm'] ?? null,
        );

        $age = self::monthsSince($attributes['date_of_birth'] ?? null);

        if ($age !== null) {
            $attributes['age_months'] = $age;
        }

        return $attributes;
    }

    /**
     * Pregnant / Lactating Women: switching between pregnant and lactating is
     * an admission into a different care cycle, so the status decides the
     * visit type exactly as it does on the form.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function pregnant(array $attributes): array
    {
        $attributes['visit_type'] = PregnantWomanDuplicateChecker::resolveVisitType(
            $attributes['mother_id'] ?? null,
            $attributes['status_type'] ?? null,
        );

        return $attributes;
    }

    /**
     * Group sessions: a participant already on an active session is attending
     * a follow-up, whatever the sheet calls it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function groupSessions(array $attributes): array
    {
        $attributes['visit_type'] = GroupSessionDuplicateChecker::resolveVisitType(
            $attributes['id_number'] ?? null,
        );

        return $attributes;
    }

    /**
     * Whole months between a date of birth and today, or null when the cell
     * holds nothing readable. A future date has no age.
     */
    private static function monthsSince(mixed $dateOfBirth): ?int
    {
        if (blank($dateOfBirth)) {
            return null;
        }

        try {
            $dob = Carbon::parse($dateOfBirth)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($dob->isFuture()) {
            return null;
        }

        return (int) $dob->diffInMonths(Carbon::today());
    }
}
