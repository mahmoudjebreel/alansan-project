<?php

namespace App\Support\Import;

/**
 * Date cells of the Children import that arrive as text rather than as a real
 * Excel date.
 *
 * The children workbooks come from the same teams, and in the same hand, as the
 * Pregnant / Lactating Women ones, so they carry the same problem this class's
 * counterpart was written for: a cell somebody typed keeps whatever separator
 * and ordering they used, and is handed to Carbon::parse() as a bare string.
 * That has two failure modes, and the quiet one is worse:
 *
 *   - "31/12/1990" and "24/11/25" are refused outright, because PHP reads a
 *     slashed date month-first and there is no thirty-first month. That is what
 *     failed rows of an otherwise valid file on "Invalid date for Mother Date
 *     of Birth";
 *   - "7/12/95" is accepted, and read as the 12th of July 1995. The workbooks
 *     are written day-first, so the row imports with the wrong date and nothing
 *     says so.
 *
 * So every date column of this module is read here first, and by shape rather
 * than by guesswork: the whole cell must match one anchored pattern, and each
 * pattern admits exactly one format. A match becomes an unambiguous Y-m-d
 * string that the existing date rule then reads the same way it always did.
 * Nothing is rounded, rolled over or guessed at - "31/4/2025" stays refused,
 * because April has no 31st, and so does any cell holding something that is not
 * a date.
 *
 * A leading one or two digit group is always the day, never the month. That is
 * the reading these workbooks are written in, and it is the whole point of
 * doing this by shape: the alternative is not "no interpretation", it is PHP's
 * American default applied silently.
 *
 * The shape table is deliberately this module's own rather than shared with
 * PregnantWomanImportDates: each module's reader is free to describe its own
 * workbooks, and the module that was already fixed is not touched to add this
 * one.
 *
 * Which columns may lose a cell rather than fail their row is decided in
 * DROPPABLE below, and only mother_date_of_birth is on that list.
 */
final class ChildImportDates
{
    /**
     * Every date column of this module, so a batch of hand-typed rows cannot
     * fail on one column after another as each is discovered in turn.
     *
     * @var array<int, string>
     */
    private const FIELDS = ['date_of_reporting', 'date_of_birth', 'mother_date_of_birth'];

    /**
     * Columns where an unreadable cell is dropped instead of failing its row.
     *
     * Only the mother's date of birth. The reporting date is NOT NULL and the
     * row is meaningless without it, and the child's own date of birth is what
     * the age in months is computed from, so an unreadable cell in either still
     * refuses the row. The mother's is optional detail on a record about the
     * child, and one mistyped cell there is not worth refusing a file of nine
     * hundred valid rows over.
     *
     * @var array<int, string>
     */
    private const DROPPABLE = ['mother_date_of_birth'];

    /**
     * shape the whole cell must match => the one format that reads it.
     *
     * Each shape is anchored and admits exactly one format, so there is no
     * ordering hazard and no format can steal a value meant for another. A
     * four-digit leading group is always the year; a one or two digit leading
     * group is always the day. Where a month name is present it settles which
     * component is the month on its own, so both orderings are safe to accept.
     *
     * PHP's "M" reads "Aug", "August" and "AUG" alike, and refuses a word that
     * is not a month at all.
     *
     * @var array<string, string>
     */
    private const SHAPES = [
        // Year first.
        '/^\d{4}-\d{1,2}-\d{1,2}$/' => 'Y-m-d',
        '/^\d{4}\/\d{1,2}\/\d{1,2}$/' => 'Y/m/d',
        '/^\d{4}\.\d{1,2}\.\d{1,2}$/' => 'Y.m.d',

        // Day first, four digit year.
        '/^\d{1,2}\/\d{1,2}\/\d{4}$/' => 'd/m/Y',
        '/^\d{1,2}-\d{1,2}-\d{4}$/' => 'd-m-Y',
        '/^\d{1,2}\.\d{1,2}\.\d{4}$/' => 'd.m.Y',

        // Day first, two digit year.
        '/^\d{1,2}\/\d{1,2}\/\d{2}$/' => 'd/m/y',
        '/^\d{1,2}-\d{1,2}-\d{2}$/' => 'd-m-y',
        '/^\d{1,2}\.\d{1,2}\.\d{2}$/' => 'd.m.y',

        // Month named, month first.
        '/^[A-Za-z]{3,9}\/\d{1,2}\/\d{4}$/' => 'M/d/Y',
        '/^[A-Za-z]{3,9}-\d{1,2}-\d{4}$/' => 'M-d-Y',
        '/^[A-Za-z]{3,9} \d{1,2} \d{4}$/' => 'M d Y',
        '/^[A-Za-z]{3,9} \d{1,2}, \d{4}$/' => 'M d, Y',

        // Month named, day first.
        '/^\d{1,2}\/[A-Za-z]{3,9}\/\d{4}$/' => 'd/M/Y',
        '/^\d{1,2}-[A-Za-z]{3,9}-\d{4}$/' => 'd-M-Y',
        '/^\d{1,2} [A-Za-z]{3,9} \d{4}$/' => 'd M Y',
    ];

    public static function handles(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    /**
     * Whether an unreadable cell in this column costs the cell or the row.
     */
    public static function mayDrop(string $field): bool
    {
        return in_array($field, self::DROPPABLE, true);
    }

    /**
     * Rewrite one text date cell as Y-m-d.
     *
     * A cell that reads as a date with certainty becomes an unambiguous Y-m-d
     * string. A cell that does not is either dropped to null - for the one
     * optional column where a single mistyped birth date is not worth refusing
     * a whole file over - or handed back exactly as it came, for the existing
     * date rule to refuse with the message it already produces.
     *
     * A blank cell is never touched: whether it is allowed is the required
     * field rule's decision, not this class's.
     *
     * Dropping is never silent; the discarded value is logged.
     */
    public static function normalise(string $field, mixed $value): mixed
    {
        if (! self::handles($field) || ! is_string($value)) {
            return $value;
        }

        // A backslash is never a date separator, only a mistyped slash.
        $candidate = str_replace('\\', '/', trim($value));

        if ($candidate === '') {
            return $value;
        }

        foreach (self::SHAPES as $shape => $format) {
            if (! preg_match($shape, $candidate)) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!' . $format, $candidate);
            $errors = \DateTimeImmutable::getLastErrors();

            // A warning here is PHP having rolled an impossible date over into
            // the next month - the 31st of April becoming the 1st of May. The
            // file says a day that does not exist, so there is no date to keep.
            $rolledOver = $errors !== false
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

            if ($date === false || $rolledOver) {
                return self::unreadable($field, $value);
            }

            return $date->format('Y-m-d');
        }

        return self::unreadable($field, $value);
    }

    /**
     * A cell this class could not read: dropped where that is allowed, and
     * otherwise passed on untouched to be refused.
     */
    private static function unreadable(string $field, string $value): ?string
    {
        if (! self::mayDrop($field)) {
            return $value;
        }

        \Illuminate\Support\Facades\Log::warning(
            'Children import: unreadable date cell dropped.',
            ['field' => $field, 'value' => $value],
        );

        return null;
    }
}
