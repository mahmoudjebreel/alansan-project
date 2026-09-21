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
 * The reading itself is not done here. Every date cell in the system - flat
 * columns, repeater columns, every module - goes through {@see ImportDateParser},
 * which holds the one shape table there is. This class used to carry a copy of
 * that table, and PregnantWomanImportDates carried an identical second one,
 * while four modules had none at all and fell through to Carbon's month-first
 * reading. One workbook could hold both readings at once, which is why the
 * table now lives in exactly one place.
 *
 * What is left here is the only thing that genuinely is this module's own: which
 * of its columns may lose an unreadable cell rather than fail its row. That is
 * decided in DROPPABLE below, and only mother_date_of_birth is on that list.
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

        // A blank cell is never touched: whether it is allowed is the
        // required-field rule's decision, not this class's.
        if (trim($value) === '') {
            return $value;
        }

        return ImportDateParser::toIsoDate($value) ?? self::unreadable($field, $value);
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
