<?php

namespace App\Support\Import;

use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;

/**
 * Whether an uploaded row describes a record the system already holds.
 *
 * Deliberately not the same question as "is this person new or returning".
 * Those two were run together, and they are not the same rule at all: a mother
 * on her fourth session is a follow-up and must import, while the same session
 * uploaded twice is a duplicate and must not. Visit type is settled by the
 * module's own resolver, which this class never touches; all that is decided
 * here is whether this exact visit, session or counseling already exists.
 *
 * Each module's key is its identity plus the thing that makes one occasion
 * different from the next:
 *
 *   Children              child ID + reporting date
 *   Pregnant / Lactating  mother ID + reporting date
 *   Mother-to-Mother      mother ID + session date
 *   Group sessions        mother ID + session date + session subject
 *   Individual counseling mother ID + counseling date
 *   Follow-up children    child ID + visit number + visit date, per visit
 *
 * The keys are the modules' own existing structures; nothing new is invented,
 * and in particular no child ID is invented for Individual Counseling, which
 * does not have one - the mother ID and the date are the whole key there.
 *
 * Every lookup runs through the model's default scope, which SoftDeletes has
 * already narrowed to live rows. A record that was deleted is not in the system
 * any more, so uploading it again is not a duplicate - exactly as the manual
 * duplicate alerts have always treated the trash.
 */
final class ImportDuplicateGuard
{
    /**
     * The reason this row is a duplicate, or null when it is not one.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $visits
     */
    public static function reason(ImportDefinition $definition, array $attributes, array $visits = []): ?string
    {
        return match ($definition->model) {
            Child::class => self::child($attributes),
            PregnantLactatingWoman::class => self::pregnantWoman($attributes),
            MotherToMotherSession::class => self::motherToMother($attributes),
            GroupSession::class => self::groupSession($attributes),
            IndividualCounseling::class => self::individualCounseling($attributes),
            FollowUpChild::class => self::followUpChild($attributes, $visits),
            default => null,
        };
    }

    /**
     * Children: the same child seen again on the same reporting date.
     *
     * The same child on a different date is a later visit, not a duplicate, and
     * still imports - as a follow-up, or as a new admission if the relapse rule
     * says so. That decision is not made here.
     */
    private static function child(array $attributes): ?string
    {
        if (! ChildImportVisits::alreadyStored($attributes)) {
            return null;
        }

        return __('fields.import_duplicate_visit');
    }

    /**
     * Pregnant / Lactating Women: the same mother on the same reporting date.
     *
     * The status is deliberately NOT part of this key. It was, briefly, on the
     * reasoning that a change of status opens a different care cycle - but that
     * is a statement about the visit *type*, not about whether the visit
     * happened twice. With the status in the key, one mother could be recorded
     * as pregnant and again as breastfeeding on the very same day and both rows
     * would be stored, which is one visit written down twice.
     *
     * A mother has one visit per reporting date. What that visit is called -
     * new or follow-up, and on which of the status transitions - is settled
     * entirely by PregnantWomanDuplicateChecker, which this class never
     * consults and has not changed.
     */
    private static function pregnantWoman(array $attributes): ?string
    {
        $exists = self::exists(
            PregnantLactatingWoman::query(),
            ['mother_id' => $attributes['mother_id'] ?? null],
            'date_of_reporting',
            $attributes['date_of_reporting'] ?? null,
        );

        return $exists ? __('fields.import_duplicate_visit') : null;
    }

    private static function motherToMother(array $attributes): ?string
    {
        $exists = self::exists(
            MotherToMotherSession::query(),
            ['id_number' => $attributes['id_number'] ?? null],
            'session_date',
            $attributes['session_date'] ?? null,
        );

        return $exists ? __('fields.import_duplicate_session') : null;
    }

    /**
     * Group sessions: the subject is part of the key, so the same mother may
     * attend two different sessions on one day.
     */
    private static function groupSession(array $attributes): ?string
    {
        $exists = self::exists(
            GroupSession::query(),
            [
                'id_number' => $attributes['id_number'] ?? null,
                'session_subject' => $attributes['session_subject'] ?? null,
            ],
            'session_date',
            $attributes['session_date'] ?? null,
        );

        return $exists ? __('fields.import_duplicate_session') : null;
    }

    /**
     * Individual counseling: the mother ID and the counseling date.
     *
     * One record carries both the child's and the mother's details, and the
     * module has no child ID - the child is reached through the mother. So the
     * mother and the date are the key, and two counseling records for one
     * mother on one day are read as the same record uploaded twice.
     */
    private static function individualCounseling(array $attributes): ?string
    {
        $exists = self::exists(
            IndividualCounseling::query(),
            ['mother_id_number' => $attributes['mother_id_number'] ?? null],
            'date',
            $attributes['date'] ?? null,
        );

        return $exists ? __('fields.import_duplicate_counseling') : null;
    }

    /**
     * Follow-up children: the visits, one at a time.
     *
     * The record itself is an episode and a child may legitimately have more
     * than one - a readmission opens a new episode for the same ID. What cannot
     * happen twice is a visit: the same child, the same visit number, the same
     * date. The visit number is left exactly as the file numbers it, so a
     * missed visit still occupies its place in the sequence and nothing after
     * it is renumbered.
     *
     * @param  array<int, array<string, mixed>>  $visits
     */
    private static function followUpChild(array $attributes, array $visits): ?string
    {
        $idNumber = $attributes['id_number'] ?? null;

        if (blank($idNumber) || $visits === []) {
            return null;
        }

        foreach ($visits as $visit) {
            $day = self::day($visit['visit_date'] ?? null);

            if ($day === null) {
                continue;
            }

            $exists = FollowUpChild::query()
                ->where('id_number', $idNumber)
                ->whereHas(
                    'visits',
                    fn ($query) => $query
                        ->where('visit_number', $visit['visit_number'])
                        ->whereDate('visit_date', $day),
                )
                ->exists();

            if ($exists) {
                return __('fields.import_duplicate_follow_up_visit', [
                    'n' => $visit['visit_number'],
                    'date' => $day,
                ]);
            }
        }

        return null;
    }

    /**
     * Whether a row already exists matching every scalar column given, on the
     * same calendar day in the named date column.
     *
     * A row whose identity or date is missing is never called a duplicate: the
     * required-field rule reports an absent value, and a guess made here would
     * hide it.
     *
     * @param  array<string, mixed>  $where
     */
    private static function exists(
        \Illuminate\Database\Eloquent\Builder $query,
        array $where,
        string $dateColumn,
        mixed $date,
    ): bool {
        $day = self::day($date);

        if ($day === null) {
            return false;
        }

        foreach ($where as $column => $value) {
            if (blank($value)) {
                return false;
            }

            $query->where($column, $value);
        }

        return $query->whereDate($dateColumn, $day)->exists();
    }

    /**
     * A date cell as a "Y-m-d" day, or null when there is no date to compare.
     *
     * The import casts a date column to a Carbon instance before this class
     * ever sees it; anything else is read as it came, so a module whose column
     * is not cast still compares correctly.
     */
    private static function day(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (! is_scalar($date) || (string) $date === '') {
            return null;
        }

        return (string) $date;
    }
}
