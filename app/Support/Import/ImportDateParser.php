<?php

namespace App\Support\Import;

/**
 * The one reader every import module uses for a date cell.
 *
 * Two identical shape tables used to live in ChildImportDates and
 * PregnantWomanImportDates, and the four modules that had neither - group
 * sessions, mother-to-mother, individual counseling, follow-up children - fell
 * through to Carbon::parse(), as did every repeater cell in every module. That
 * fallback reads a slashed date month-first, so "03/04/2026" imported as the
 * 4th of March in exactly the columns nobody had got round to covering, and as
 * the 3rd of April in the two that had been. One workbook could therefore hold
 * both readings at once.
 *
 * So the table lives here, once, and everything reads through it:
 *
 *   - ImportSchema::castDate() for every flat date column of every module;
 *   - AbstractTableImport::parseDate() for the numbered visit and session
 *     columns;
 *   - ChildImportDates and PregnantWomanImportDates, which no longer carry a
 *     table of their own and now decide one thing only - whether an unreadable
 *     cell costs the cell or the whole row.
 *
 * A one or two digit leading group is always the DAY, never the month. That is
 * how these workbooks are written, and it is the whole point of reading by
 * shape: the alternative is not "no interpretation", it is PHP's American
 * default applied in silence.
 *
 * Nothing here rounds, rolls over or guesses. "31/4/2025" is not the 31st of
 * April read back to front, it is a day that never happened, and it is refused
 * rather than rolled into the 1st of May.
 */
final class ImportDateParser
{
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
    public const SHAPES = [
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

    /**
     * The time half of a date-time cell, which is dropped before the date is
     * read.
     *
     * Only the date portion is stored anywhere in this system, and a cell
     * reading "03/04/2026 14:30" is the same date as "03/04/2026". Left in
     * place it matched none of the shapes above, fell through to Carbon and
     * came back as the 4th of March - the one reading that must never happen.
     * No time-based rule is introduced by removing it; the value was being
     * discarded by startOfDay() either way.
     */
    private const TIME_SUFFIX = '/[\p{Z}T]+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*([AaPp]\.?[Mm]\.?)?$/u';

    /**
     * Whether a cell says nothing at all.
     *
     * True for a cell that is empty once every kind of blank is removed, and
     * for one holding only punctuation used as a placeholder - "-", "--", "/".
     * PHP's trim() stops at the ASCII space, so a non-breaking space, a
     * zero-width joiner or a stray BOM used to survive it and be read as a
     * value; \p{Z} and \p{C} cover all three.
     *
     * A cell carrying any letter or digit is never a placeholder, so a real
     * answer can never be discarded here.
     */
    public static function isPlaceholder(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $stripped = preg_replace('/[\p{Z}\p{C}]/u', '', $value) ?? $value;

        return $stripped === '' || preg_match('/[\p{L}\p{N}]/u', $stripped) !== 1;
    }

    /**
     * Whether a date cell states that there is no date.
     *
     * Beyond the blank and placeholder shapes isPlaceholder() covers, two
     * spellings of "none" are specific to date columns:
     *
     *   - a serial of zero or less. Excel counts days from serial 1, so a zero
     *     is not the 30th of December 1899, it is a date column nobody filled
     *     in;
     *   - a cell whose digits are all zeros - "0", "00/00/0000", "0000-00-00" -
     *     which is what a form or an export writes for an absent date;
     *   - a boolean FALSE, which is how a sheet storing real Excel booleans
     *     leaves a date column unanswered.
     *
     * Anything holding a non-zero digit is left alone and still has to parse,
     * so a real date can never be dropped here. Whether an absent date is
     * allowed at all is the required-field rule's decision, never this class's.
     */
    public static function statesNoDate(mixed $value): bool
    {
        if (self::isPlaceholder($value)) {
            return true;
        }

        // A date column whose cells arrived as Excel booleans. FALSE is that
        // sheet's way of writing "no date", and it used to reach Carbon as the
        // empty string - which Carbon reads as today, so the row imported with
        // today's date and said nothing. TRUE is not a date under any reading
        // and is left to be refused below.
        if ($value === false) {
            return true;
        }

        if (is_numeric($value)) {
            return (float) $value <= 0;
        }

        if (! is_string($value)) {
            return false;
        }

        // A month name makes the cell a date attempt, not a "none" marker.
        if (preg_match('/\p{L}/u', $value) === 1) {
            return false;
        }

        $digits = preg_replace('/\D/u', '', $value) ?? '';

        return $digits !== '' && trim($digits, '0') === '';
    }

    /**
     * Read one typed date cell as an unambiguous "Y-m-d" string.
     *
     * Returns null when the cell matches no shape, or matches one but names a
     * day that does not exist. The caller decides what that costs - a dropped
     * cell or a refused row - because only the caller knows which column it is
     * reading.
     *
     * Excel serials are not this method's business: there is nothing ambiguous
     * about a number, and the caller turns one into a date directly.
     */
    public static function toIsoDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // A backslash is never a date separator, only a mistyped slash.
        $candidate = trim(str_replace('\\', '/', $value));

        if ($candidate === '') {
            return null;
        }

        // Only the date portion of a date-time cell is ever stored.
        $candidate = trim(preg_replace(self::TIME_SUFFIX, '', $candidate) ?? $candidate);

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
                return null;
            }

            return $date->format('Y-m-d');
        }

        return null;
    }

    /**
     * The same reading, for a caller that wants an unreadable cell handed back
     * untouched so the ordinary date rule can refuse it by name.
     */
    public static function normalise(mixed $value): mixed
    {
        return self::toIsoDate($value) ?? $value;
    }
}
