<?php

namespace App\Support\Import;

use App\Models\Child;
use App\Support\ChildDuplicateChecker;

/**
 * The Children rows of one upload, read as the visits they are.
 *
 * A Children record is one visit: the same child ID appears once per reporting
 * date. The relapse rule that decides a visit's type compares it against the
 * child's latest active visit, so the answer depends on what was already
 * stored when the visit is written - which, for rows of the same file, is the
 * rows written before it.
 *
 * Deriving at read time gave every row of a file the same prior state, the one
 * from before the file was opened: a child screened in June and again in July
 * in one workbook was "new" twice, because neither row could see the other.
 * These three steps run at commit time instead, and only for this module.
 *
 * The value the sheet states for the visit type is read and then discarded
 * exactly as before; nothing here trusts it.
 */
final class ChildImportVisits
{
    /**
     * The rows in the order the visits happened: by reporting date, and by
     * position in the file for two visits on the same day.
     *
     * A file is not always written in date order, and a July row saved before
     * a June one would have made June the "follow-up" of July.
     *
     * @param  array<int, array{row: int, attributes: array, visits: array, followups: array}>  $rows
     * @return array<int, array{row: int, attributes: array, visits: array, followups: array}>
     */
    public static function inVisitOrder(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            return [self::day($a['attributes']['date_of_reporting'] ?? null), $a['row']]
                <=> [self::day($b['attributes']['date_of_reporting'] ?? null), $b['row']];
        });

        return $rows;
    }

    /**
     * Whether this visit is already in the system: an active record for the
     * same child ID on the same reporting date. Uploading a file twice used to
     * store every visit twice.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function alreadyStored(array $attributes): bool
    {
        $childId = $attributes['child_id'] ?? null;
        $day = self::day($attributes['date_of_reporting'] ?? null);

        if (blank($childId) || $day === '') {
            return false;
        }

        return Child::query()
            ->where('child_id', $childId)
            ->whereDate('date_of_reporting', $day)
            ->exists();
    }

    /**
     * The visit type as the relapse rule decides it now, against every visit
     * stored so far - including the earlier rows of the same file.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function visitType(array $attributes): string
    {
        return ChildDuplicateChecker::resolveVisitType(
            $attributes['child_id'] ?? null,
            $attributes['muac_mm'] ?? null,
        );
    }

    /**
     * A reporting date as a sortable Y-m-d string. The import casts the cell
     * to a Carbon instance; anything else is compared as it came.
     */
    private static function day(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return is_scalar($date) ? (string) $date : '';
    }
}
