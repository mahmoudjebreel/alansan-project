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
            $value = trim($value);

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

        return [
            'ok' => false,
            'message' => __('fields.import_invalid_boolean', [
                'field' => __('fields.' . $field),
                'allowed' => __('fields.yes') . ' / ' . __('fields.no'),
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

        return [
            'ok' => false,
            'message' => __('fields.import_invalid_option', [
                'field' => __('fields.' . $field),
                'allowed' => collect($options)->map(fn ($label, $raw) => (string) $label)->implode(' / '),
            ]),
        ];
    }

    /**
     * Read one date cell.
     *
     * Blankness is decided here and nowhere else, because the shapes a blank
     * date cell arrives in are not the empty string castValue() already
     * catches. A column the sheet formats as a date but nobody fills in comes
     * back as a hard 0, as a false, or as spacing that trim() does not touch -
     * a no-break space, a zero-width mark, a BOM left by an export. All of
     * them used to be parsed: 0 became 1970-01-01, and a no-break space became
     * *today*, silently, in a NOT NULL reporting date. None of them is a date,
     * so each is read as blank.
     *
     * Reading them as blank does not make any column optional. A blank value
     * is handed to validateRow(), which refuses the row through the existing
     * required-field rule for every NOT NULL column; only a genuinely optional
     * column such as newborn_dob keeps the null.
     */
    private function castDate(string $field, mixed $value): array
    {
        // A reader that hands back a real date object has already done the
        // parsing, and stringifying it would only throw.
        if ($value instanceof \DateTimeInterface) {
            return ['ok' => true, 'value' => Carbon::instance($value)->startOfDay()];
        }

        // A cell formatted as a date and left empty: 0, false, or spacing of
        // any width. Not a date, and not this method's call to refuse.
        if (is_bool($value)) {
            return ['ok' => true, 'value' => null];
        }

        if (is_numeric($value) && (float) $value === 0.0) {
            return ['ok' => true, 'value' => null];
        }

        if (is_string($value) && preg_replace('/[\p{Z}\s\x{200B}-\x{200F}\x{FEFF}]+/u', '', $value) === '') {
            return ['ok' => true, 'value' => null];
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
        $value = preg_replace('/[\x{0640}\x{064B}-\x{0652}]/u', '', $value) ?? $value;
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
