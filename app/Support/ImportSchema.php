<?php

namespace App\Support;

use App\Imports\ImportDefinition;
use Carbon\Carbon;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Translates an uploaded spreadsheet back into model attributes.
 *
 * Exports write translated headings and translated values, so importing has to
 * reverse that. Enum options are read from the module's Filament form, so an
 * uploaded label always maps back to the value manual entry would have stored;
 * required columns come from the table itself (see requiredFields()).
 */
final class ImportSchema
{
    /** Locales an uploaded file may have been produced in. */
    private const LOCALES = ['ar', 'en'];

    /**
     * The three columns one follow-up session occupies, as column type => the
     * translation keys its heading may carry. The first key is the heading the
     * export and the template write today; the rest are headings earlier
     * versions wrote, accepted on the way in so a file downloaded before a
     * rename still uploads.
     */
    private const FOLLOWUP_COLUMNS = [
        'followup_date' => ['followup_date_n', 'followup_date_n_alt', 'followup_date_n_alt2'],
        'followup_assess' => ['followup_assess_n', 'followup_assess_n_alt'],
        'followup_act' => ['followup_act_n', 'followup_act_n_alt'],
    ];

    /**
     * How far past the maximum a session heading is still recognised, purely so
     * an over-long file can be refused by name instead of silently ignored.
     */
    private const FOLLOWUP_OVERFLOW_SCAN = 40;

    private ?array $selectOptions = null;

    private ?array $requiredFields = null;

    public function __construct(private readonly ImportDefinition $definition)
    {
    }

    // ---------------------------------------------------------------------
    // Headings
    // ---------------------------------------------------------------------

    /**
     * Expected headings, in export order, for the current locale.
     *
     * @return array<string>
     */
    public function headings(): array
    {
        $headings = array_map(
            fn (string $field): string => __('fields.' . $field),
            $this->definition->fields(),
        );

        if ($this->definition->hasVisits()) {
            foreach ($this->visitNumbers() as $i) {
                $headings[] = __('fields.visit_date_n', ['n' => $i]);
                $headings[] = __('fields.visit_muac_n', ['n' => $i]);
            }
        }

        if ($this->definition->hasFollowups()) {
            foreach ($this->followupNumbers() as $i) {
                foreach (self::FOLLOWUP_COLUMNS as $keys) {
                    // The first key is the heading of record; the rest are only
                    // ever read, never written.
                    $headings[] = __('fields.' . $keys[0], ['n' => $i]);
                }
            }
        }

        return $headings;
    }

    /**
     * Visit column numbers included in the Follow Up template (1..MAX_VISITS).
     *
     * @return array<int>
     */
    public function visitNumbers(): array
    {
        return range(1, \App\Models\FollowUpChild::MAX_VISITS);
    }

    /**
     * Follow-up session column numbers included in the template (1..max).
     *
     * @return array<int>
     */
    public function followupNumbers(): array
    {
        return range(1, $this->definition->maxFollowups());
    }

    /**
     * Resolve one uploaded heading to a field name, or to a visit column.
     *
     * Accepts the translated label in any supported locale as well as the raw
     * database column name, so files exported in either language still import.
     *
     * @return array{type: string, field?: string, number?: int}|null
     */
    public function resolveHeading(?string $heading): ?array
    {
        $heading = $this->normalise($heading);

        if ($heading === '') {
            return null;
        }

        foreach ($this->definition->fields() as $field) {
            if ($heading === $this->normalise($field)) {
                return ['type' => 'field', 'field' => $field];
            }

            foreach (self::LOCALES as $locale) {
                if ($heading === $this->normalise(trans('fields.' . $field, [], $locale))) {
                    return ['type' => 'field', 'field' => $field];
                }
            }
        }

        // Only now: a heading the module accepts as an alias of a real column.
        // Checked after every canonical spelling, exactly as the value synonyms
        // are, so an alias can never take a column away from the heading the
        // export itself writes.
        $fields = $this->definition->fields();

        foreach ($this->definition->headingAliases as $field => $spellings) {
            if (! in_array($field, $fields, true)) {
                continue; // An alias for a column this module does not carry.
            }

            foreach ($spellings as $spelling) {
                if ($heading === $this->normalise((string) $spelling)) {
                    return ['type' => 'field', 'field' => $field];
                }
            }
        }

        if ($this->definition->hasVisits()) {
            foreach ($this->visitNumbers() as $i) {
                foreach (self::LOCALES as $locale) {
                    if ($heading === $this->normalise(trans('fields.visit_date_n', ['n' => $i], $locale))) {
                        return ['type' => 'visit_date', 'number' => $i];
                    }

                    if ($heading === $this->normalise(trans('fields.visit_muac_n', ['n' => $i], $locale))) {
                        return ['type' => 'visit_muac', 'number' => $i];
                    }
                }
            }
        }

        if ($this->definition->hasFollowups()) {
            return $this->resolveFollowupHeading($heading);
        }

        return null;
    }

    /**
     * Resolve one numbered follow-up session heading.
     *
     * Numbers past the allowed maximum are still recognised, and reported as an
     * overflow: a seventh session column has to fail the upload with a message
     * naming it, not disappear into the ignored-columns bucket.
     *
     * @return array{type: string, number: int}|null
     */
    private function resolveFollowupHeading(string $heading): ?array
    {
        $limit = $this->definition->maxFollowups();

        foreach (range(1, $limit + self::FOLLOWUP_OVERFLOW_SCAN) as $i) {
            foreach (self::FOLLOWUP_COLUMNS as $type => $keys) {
                foreach ($keys as $translationKey) {
                    foreach (self::LOCALES as $locale) {
                        if ($heading !== $this->normalise(trans('fields.' . $translationKey, ['n' => $i], $locale))) {
                            continue;
                        }

                        return $i > $limit
                            ? ['type' => 'followup_overflow', 'number' => $i]
                            : ['type' => $type, 'number' => $i];
                    }
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------------

    /**
     * Convert one uploaded cell into the value the model expects.
     *
     * Returns ['ok' => true, 'value' => mixed] or ['ok' => false, 'message' => string].
     */
    public function castValue(string $field, mixed $value): array
    {
        if (is_string($value)) {
            $value = self::clean($value);

            // A sheet typed by hand spells the same thing with one space and
            // with three; squeeze them so the two do not become two spellings.
            if (in_array($field, $this->definition->collapseWhitespace, true)) {
                $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            }
        }

        if ($value === null || $value === '') {
            return ['ok' => true, 'value' => null];
        }

        if (in_array($field, $this->definition->booleanFields(), true)) {
            return $this->castBoolean($field, $value);
        }

        if (in_array($field, $this->definition->enumFields(), true) || $this->optionsFor($field) !== null) {
            return $this->castEnum($field, $value);
        }

        if ($this->isDateField($field)) {
            return $this->castDate($field, $value);
        }

        return ['ok' => true, 'value' => $value];
    }

    private function castBoolean(string $field, mixed $value): array
    {
        // A cell holding nothing but blanks, zero-width marks or a placeholder
        // dash is an unanswered question, not a wrong answer. castValue()
        // already reads a genuinely empty cell that way; "-" and a
        // non-breaking space are the same statement typed differently, and
        // failing a row over one was refusing a sheet for saying nothing. A
        // NOT NULL column still ends up false, through defaults().
        if ($this->isPlaceholder($value)) {
            return ['ok' => true, 'value' => null];
        }

        // A cell that already is a boolean. A workbook whose Yes/No columns
        // hold real Excel TRUE/FALSE values - rather than the words - hands
        // PHP a bool, and (string) false is the empty string, which matched
        // none of the spellings below. So every row answering "no" in such a
        // column was refused, while the very same column answered "No" as text
        // imported without a murmur. The value needs no reading at all.
        if (is_bool($value)) {
            return ['ok' => true, 'value' => $value];
        }

        // A cell that arrived as a number. 1 and 0 already read correctly as
        // strings, but a spreadsheet storing them as decimals writes "1.0" and
        // "0.00", which do not. Only those two values are accepted: a 2 in a
        // Yes/No column is a mistake, not an answer, and still fails.
        if (is_numeric($value)) {
            $number = (float) $value;

            if ($number === 1.0 || $number === 0.0) {
                return ['ok' => true, 'value' => $number === 1.0];
            }
        }

        $needle = $this->normalise((string) $value);

        $truthy = ['1', 'true', 'yes', 'y'];
        $falsy = ['0', 'false', 'no', 'n'];

        foreach (self::LOCALES as $locale) {
            $truthy[] = $this->normalise(trans('fields.yes', [], $locale));
            $falsy[] = $this->normalise(trans('fields.no', [], $locale));
        }

        if (in_array($needle, $truthy, true)) {
            return ['ok' => true, 'value' => true];
        }

        if (in_array($needle, $falsy, true)) {
            return ['ok' => true, 'value' => false];
        }

        // Only now: a spelling the module accepts as an alias of yes or no.
        // Checked last, exactly as the enum aliases are, so a module map can
        // never redefine نعم / لا themselves.
        foreach ($this->definition->synonyms[$field] ?? [] as $alias => $stored) {
            if ($needle === $this->normalise((string) $alias)) {
                return ['ok' => true, 'value' => filter_var($stored, FILTER_VALIDATE_BOOL)];
            }
        }

        return [
            'ok' => false,
            'message' => __('fields.import_invalid_boolean', [
                // Naming the cell that was refused, exactly as the enum message
                // does. Without it a Yes/No column that has quietly been filled
                // from the wrong source column - a marital status under "has a
                // lactating woman" - reports five hundred identical lines that
                // say only which column is unhappy, and the misalignment that
                // caused them stays invisible.
                'value' => self::describe($value),
                'field' => __('fields.' . $field),
                'allowed' => $this->acceptedSpellings($field, [
                    __('fields.yes'),
                    __('fields.no'),
                ]),
            ]),
        ];
    }

    private function castEnum(string $field, mixed $value): array
    {
        $options = $this->optionsFor($field);

        if ($options === null || $options === []) {
            // A Select whose options are genuinely dynamic tells us nothing about
            // a free-text column: keep it as-is, the way it always was.
            if (! in_array($field, $this->definition->enumFields(), true)) {
                return ['ok' => true, 'value' => $value];
            }

            // A column the module itself declares as an enum is a different
            // matter. Writing an unchecked cell into it is what turned a normal
            // typo into a raw "Data truncated for column 'category'" SQL warning,
            // so refuse the row and say so in words the uploader can act on.
            return [
                'ok' => false,
                'message' => __('fields.import_unreadable_option', ['field' => __('fields.' . $field)]),
            ];
        }

        $needle = $this->normalise((string) $value);

        foreach ($options as $raw => $label) {
            // Accept the stored value itself...
            if ($needle === $this->normalise((string) $raw)) {
                return ['ok' => true, 'value' => $raw];
            }

            // ...the label as rendered in the form...
            if ($needle === $this->normalise((string) $label)) {
                return ['ok' => true, 'value' => $raw];
            }

            // ...or the translation of the stored value in either locale.
            foreach (self::LOCALES as $locale) {
                if ($needle === $this->normalise(trans('fields.' . $raw, [], $locale))) {
                    return ['ok' => true, 'value' => $raw];
                }
            }
        }

        // Only now: a spelling the module accepts as an alias of a real option.
        // Checked last, so an alias can never shadow an option of the same name.
        foreach ($this->definition->synonyms[$field] ?? [] as $alias => $stored) {
            if ($needle === $this->normalise((string) $alias) && array_key_exists($stored, $options)) {
                return ['ok' => true, 'value' => $stored];
            }
        }

        // The message names the cell that was refused as well as what would
        // have been accepted. Without the value, an uploader looking at a
        // sheet of eighteen hundred rows is told a column is wrong somewhere
        // and left to find it.
        return [
            'ok' => false,
            'message' => __('fields.import_invalid_option', [
                'value' => is_scalar($value) ? trim((string) $value) : '—',
                'field' => __('fields.' . $field),
                'allowed' => $this->acceptedSpellings($field, $options),
            ]),
        ];
    }

    /**
     * Strip the invisible marks a cell carries around its real value.
     *
     * An Arabic sheet is full of Unicode format characters - the right-to-left
     * mark above all - that a spreadsheet inserts to lay a mixed-direction cell
     * out and that carry no data whatsoever. trim() stops at the ASCII space,
     * so a cell holding U+200F followed by "0" reached the numeric check as a
     * two-character string, failed it, and reported a rejected value that looked
     * identical to a plain zero on screen. \p{Cf} covers the direction marks,
     * the zero-width joiners and a stray byte-order mark alike; nothing in it
     * is ever part of an answer.
     */
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim(preg_replace('/\p{Cf}/u', '', $value) ?? $value);
    }

    /**
     * One rejected cell, rendered for a message.
     *
     * Booleans and arrays reach here too - a sheet storing real Excel booleans
     * hands PHP a bool, and (string) false is the empty string, which would
     * print a message naming no value at all.
     */
    public static function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '—';
    }

    /**
     * Everything a file may legitimately write in this column: the Select's
     * own labels plus the spellings the module accepts as aliases of them.
     *
     * Listing only the labels was misleading on a module with a synonym map -
     * it told an uploader that "Breastfeeding" was not allowed while the
     * importer was perfectly willing to read it.
     *
     * @param  array<string, mixed>  $options
     */
    private function acceptedSpellings(string $field, array $options): string
    {
        $labels = collect($options)
            ->map(fn ($label): string => (string) $label)
            ->values();

        $aliases = collect(array_keys($this->definition->synonyms[$field] ?? []))
            ->map(fn ($alias): string => (string) $alias);

        return $labels
            ->merge($aliases)
            ->unique(fn (string $spelling): string => $this->normalise($spelling))
            ->implode(' / ');
    }

    private function castDate(string $field, mixed $value): array
    {
        // A date cell that states no date, before anything tries to read one
        // out of it. This is shared by every module on purpose: the shapes a
        // sheet writes for "there is no date here" are the same whichever
        // module it belongs to, and each of them used to end badly. A lone
        // dash was refused, taking an otherwise valid row with it; a
        // non-breaking space survived trim() and was handed to Carbon, which
        // read it as today and stored today's date silently; a zero - the
        // serial Excel leaves in a date column that was never filled in - was
        // read as the 1st of January 1970, just as silently.
        //
        // The column is simply left empty. Whether that is allowed is the
        // required-field rule's decision, exactly as it is for a cell that was
        // genuinely left blank.
        if ($this->statesNoDate($value)) {
            return ['ok' => true, 'value' => null];
        }

        // A backslash where a date wants a slash. The sheets carry "06\17\2026"
        // beside "06/30/2026" in the very same column - one keyboard away from
        // each other, and one of the two unreadable to Carbon. A backslash
        // separates nothing else in a date, so turning it into the separator it
        // was meant to be cannot change how any real date reads.
        if (is_string($value)) {
            $value = str_replace('\\', '/', $value);
        }

        // A month name separated by slashes - "Jul/13/2026", which one team
        // types throughout. Carbon reads "Jul 13 2026" and refuses the very
        // same date written with slashes, so the separator is swapped for the
        // space it wants. The month name is what makes this safe: it says which
        // part is the month, so no reading of the date can change. A purely
        // numeric "06/30/2026" carries no such marker and is left alone.
        if (is_string($value) && preg_match('/\p{L}/u', $value) === 1 && str_contains($value, '/')) {
            $value = str_replace('/', ' ', $value);
        }

        // A date written out with an Arabic month name - "21 يونيو 2026".
        // Carbon knows the English month names and nothing else, so a sheet
        // filled in Arabic had every one of these refused. The month name is
        // read here and the cell rebuilt in a form Carbon cannot misread.
        if (is_string($value) && preg_match('/\p{Arabic}/u', $value) === 1) {
            $value = $this->readArabicMonth($value) ?? $value;
        }

        // A slashed date whose first number is past twelve. Carbon reads a
        // slashed date month-first, so "14/12/1998" and "20/01/26" were refused
        // as a fourteenth and a twentieth month - while "07/12/1998", typed by
        // the same hand on the same day, imported quietly as the 12th of July.
        // A number above twelve can only be a day, so this one shape can be
        // read with certainty; anything genuinely ambiguous is left to the
        // module's own reader, which is where that decision belongs.
        if (is_string($value) && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2}|\d{4})$#', $value, $m) === 1 && (int) $m[1] > 12) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = mb_strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            // Only a day that exists. "31/4/2025" is not the 31st of April
            // written back to front, it is a date that never happened - and
            // rebuilding it as 2025-04-31 would not refuse it, it would roll it
            // silently into the 1st of May. Left as it is, the ordinary rule
            // below still turns it down, which is the answer it deserves.
            if (checkdate($month, $day, $year)) {
                $value = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // A module may read its own hand-typed date cells first. Excel serials
        // never reach it: the branch below already turns those into dates
        // correctly, and there is nothing ambiguous about a number.
        $reader = $this->definition->dateReader;

        if ($reader !== null && ! is_numeric($value)) {
            $value = $reader::normalise($field, $value);

            // The reader dropped an unreadable cell in a column where that
            // costs the cell rather than the whole row.
            if ($value === null) {
                return ['ok' => true, 'value' => null];
            }
        }

        try {
            if (is_numeric($value)) {
                return ['ok' => true, 'value' => Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay()];
            }

            return ['ok' => true, 'value' => Carbon::parse((string) $value)->startOfDay()];
        } catch (\Throwable) {
            return [
                'ok' => false,
                'message' => __('fields.import_invalid_date', ['field' => __('fields.' . $field)]),
            ];
        }
    }

    /**
     * A date whose month is written as an Arabic word, as "Y-m-d".
     *
     * Both naming systems are accepted: the Levantine names a Gaza sheet is as
     * likely to use as not - كانون الثاني, آذار, تشرين الأول - and the
     * transliterated ones - يناير, مارس, أكتوبر. Day-first and month-first are
     * both read, because the month name says which number is which and leaves
     * nothing to guess at.
     *
     * Returns null when the cell is not a date of this shape, so the ordinary
     * rules still decide what becomes of it.
     */
    private function readArabicMonth(string $value): ?string
    {
        static $months = null;

        $months ??= [
            1 => ['يناير', 'كانون الثاني', 'كانون ثاني'],
            2 => ['فبراير', 'شباط'],
            3 => ['مارس', 'آذار'],
            4 => ['أبريل', 'ابريل', 'نيسان'],
            5 => ['مايو', 'أيار'],
            6 => ['يونيو', 'يونية', 'حزيران'],
            7 => ['يوليو', 'يولية', 'تموز'],
            8 => ['أغسطس', 'اغسطس', 'آب'],
            9 => ['سبتمبر', 'أيلول'],
            10 => ['أكتوبر', 'اكتوبر', 'تشرين الأول', 'تشرين اول'],
            11 => ['نوفمبر', 'تشرين الثاني', 'تشرين ثاني'],
            12 => ['ديسمبر', 'كانون الأول', 'كانون اول'],
        ];

        $needle = $this->normalise($value);

        foreach ($months as $number => $names) {
            foreach ($names as $name) {
                $word = $this->normalise($name);

                if (! str_contains($needle, $word)) {
                    continue;
                }

                // Whatever is left once the month is taken out: a day and a
                // year, in either order.
                $rest = trim(str_replace($word, ' ', $needle));

                if (preg_match('/^(\d{1,2})\D+(\d{4})$/u', $rest, $m) === 1) {
                    return sprintf('%04d-%02d-%02d', (int) $m[2], $number, (int) $m[1]);
                }

                if (preg_match('/^(\d{4})\D+(\d{1,2})$/u', $rest, $m) === 1) {
                    return sprintf('%04d-%02d-%02d', (int) $m[1], $number, (int) $m[2]);
                }

                return null;
            }
        }

        return null;
    }

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
    private function isPlaceholder(mixed $value): bool
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
     * so a real date can never be dropped here.
     */
    private function statesNoDate(mixed $value): bool
    {
        if ($this->isPlaceholder($value)) {
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

    // ---------------------------------------------------------------------
    // Form-derived metadata
    // ---------------------------------------------------------------------

    /**
     * Select options declared on the module's Create form, keyed by stored value.
     */
    public function optionsFor(string $field): ?array
    {
        return $this->selectOptions()[$field] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public function selectOptions(): array
    {
        return $this->selectOptions ??= $this->readForm()['options'];
    }

    /**
     * Fields a row must carry a value for.
     *
     * Deliberately the columns the database refuses to store as NULL, and only
     * those. The manual Create form's `required()` flags are data-entry rules
     * for one record typed by hand, not integrity rules: a bulk upload of
     * historical data legitimately leaves optional detail blank, and holding it
     * to the form's rules would reject whole files over columns the table
     * itself is happy to leave empty. Deriving the list from the schema instead
     * turns what would be a raw SQL error into a clear, row-numbered message,
     * which is the job this check actually has to do.
     *
     * @return array<string>
     */
    public function requiredFields(): array
    {
        if ($this->requiredFields !== null) {
            return $this->requiredFields;
        }

        // Booleans are excluded: a blank toggle simply means "no" (see defaults()).
        return $this->requiredFields = array_values(array_diff(
            $this->notNullFields(),
            $this->definition->booleanFields(),
        ));
    }

    /**
     * Values applied when a cell is left blank.
     *
     * A NOT NULL boolean mirrors the Create form's unchecked toggle: false.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach (array_intersect($this->notNullFields(), $this->definition->booleanFields()) as $field) {
            $defaults[$field] = false;
        }

        return $defaults;
    }

    /**
     * Import columns the database stores as NOT NULL with no default.
     *
     * @return array<string>
     */
    public function notNullFields(): array
    {
        static $cache = [];

        $table = (new ($this->definition->model))->getTable();

        if (isset($cache[$table])) {
            return array_values(array_intersect($cache[$table], $this->definition->fields()));
        }

        $strict = [];

        try {
            foreach (\Illuminate\Support\Facades\Schema::getColumns($table) as $column) {
                if (! $column['nullable'] && $column['default'] === null && ! $column['auto_increment']) {
                    $strict[] = $column['name'];
                }
            }
        } catch (\Throwable) {
            // Introspection is best-effort; the DB remains the final guard.
        }

        $cache[$table] = $strict;

        return array_values(array_intersect($strict, $this->definition->fields()));
    }

    private function isDateField(string $field): bool
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new ($this->definition->model);

        $cast = $model->getCasts()[$field] ?? null;

        return in_array($cast, ['date', 'datetime', 'immutable_date'], true)
            || str_starts_with((string) $cast, 'date:');
    }

    /**
     * Walk the module's Filament form once, collecting the Select option lists
     * an uploaded label has to be translated back through. Repeater contents
     * are skipped: they belong to the visits sub-table, not to the flat import
     * row. The form's required() flags are not read here on purpose; see
     * requiredFields().
     *
     * @return array{options: array<string, array>}
     */
    private function readForm(): array
    {
        $options = [];

        $schema = null;

        $walk = function (iterable $components) use (&$walk, &$options, &$schema): void {
            foreach ($components as $component) {
                if ($component instanceof Repeater) {
                    continue;
                }

                // Filament lets options() be a closure, and evaluating one needs
                // the component's container to resolve its arguments. A component
                // read straight off the form is detached, so getOptions() throws
                // before it can return anything and the column silently loses its
                // option list - which is how the Arabic label of a Category
                // reached SQL raw and came back as "Data truncated for column
                // 'category'". Handing the component the form's own container lets
                // those closures run, with $record resolving to null exactly as it
                // does on a blank Create form.
                try {
                    $component->container($schema);
                } catch (\Throwable) {
                    // Anything that will not take a container simply keeps the
                    // degraded behaviour handled below.
                }

                $name = null;

                if (method_exists($component, 'getName')) {
                    try {
                        $name = $component->getName();
                    } catch (\Throwable) {
                        $name = null;
                    }
                }

                if ($name !== null) {
                    if ($component instanceof Select) {
                        try {
                            $options[$name] = $component->getOptions();
                        } catch (\Throwable) {
                            // Dynamic option sets are simply not reversible; skip.
                        }
                    }

                }

                try {
                    $children = $component->getDefaultChildComponents();
                } catch (\Throwable) {
                    $children = [];
                }

                if ($children) {
                    $walk($children);
                }
            }
        };

        try {
            $schema = ($this->definition->resource)::form(new Schema());
            $walk($schema->getComponents());
        } catch (\Throwable) {
            // A form we cannot introspect simply contributes no option lists.
        }

        return ['options' => $options];
    }

    /**
     * Normalise for comparison: trim, collapse whitespace, strip Arabic
     * diacritics/tatweel and unify alef/ya forms so minor typing differences
     * in a hand-edited sheet still match.
     */
    private function normalise(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\p{Cf}/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{0640}\x{064B}-\x{0652}]/u', '', $value) ?? $value;
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
