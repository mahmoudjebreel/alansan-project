<?php

namespace App\Support;

use App\Models\FollowUpChild;
use App\Support\Import\ImportDateParser;

/**
 * The discharge date rules for a follow-up episode, in one place so the manual
 * form and the Excel import cannot enforce different ones.
 *
 * Two rules, and neither of them fills anything in:
 *
 *   1. An episode that has CLOSED must say when. Every outcome in
 *      FollowUpChild::CLOSING_OUTCOMES closes the episode - cured, defaulted,
 *      discharged to OTP, discharged elsewhere, referred for a medical reason,
 *      non-responded, died - and each one requires a discharge date. The single
 *      exception is "under follow-up", which is the outcome an OPEN episode
 *      carries; an open episode has no discharge date because it has not been
 *      discharged.
 *
 *   2. A discharge cannot precede the admission it ends.
 *
 * Nothing here guesses. A missing discharge date is not filled with today's,
 * and it is not read out of the last visit: a row that closes an episode
 * without saying when is refused and reported, because the reports count the
 * discharge in its month and an invented month is a wrong number nobody can
 * see is wrong.
 *
 * The rules read values, never records, so the same call serves a half-filled
 * form and an uploaded row.
 */
final class FollowUpDischargeRule
{
    /**
     * Whether this outcome closes the episode and therefore needs a date.
     */
    public static function closes(mixed $outcome): bool
    {
        return is_string($outcome) && in_array($outcome, FollowUpChild::CLOSING_OUTCOMES, true);
    }

    /**
     * Every rule this combination breaks, as messages ready to show.
     *
     * @return array<int, string>
     */
    public static function violations(mixed $outcome, mixed $dischargeDate, mixed $admissionDate): array
    {
        $messages = [];

        $discharge = self::day($dischargeDate);

        if (self::closes($outcome) && $discharge === null) {
            $messages[] = __('fields.discharge_date_required_for_outcome', [
                'outcome' => __('fields.' . $outcome),
            ]);
        }

        $admission = self::day($admissionDate);

        if ($discharge !== null && $admission !== null && $discharge < $admission) {
            $messages[] = __('fields.discharge_date_before_admission', [
                'discharge' => $discharge,
                'admission' => $admission,
            ]);
        }

        return $messages;
    }

    /**
     * The same rules, applied to one uploaded row.
     *
     * Named by the follow-up module's import definition, so the engine stays
     * module-agnostic and no other module pays for a rule that is not theirs.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    public static function forImportedRow(array $attributes): array
    {
        return self::violations(
            $attributes['discharge_outcome'] ?? null,
            $attributes['discharge_date'] ?? null,
            $attributes['admission_date'] ?? null,
        );
    }

    /**
     * One date as a comparable "Y-m-d" day, or null when there is none.
     *
     * Y-m-d strings compare correctly as strings, which is why the comparison
     * above needs no date objects at all.
     */
    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (ImportDateParser::statesNoDate($value) || blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        // A form hands over "2026-08-19"; a sheet may have handed over anything
        // the shared parser reads. Both end up as the same comparable day.
        return ImportDateParser::toIsoDate($value);
    }
}
