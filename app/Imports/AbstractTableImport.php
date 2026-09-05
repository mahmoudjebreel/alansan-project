<?php

namespace App\Imports;

use App\Support\ImportSchema;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * Shared import engine for every module.
 *
 * Reads a sheet whose headings were produced by the module's Export class,
 * maps each row back to model attributes, and validates it with the same
 * rules the manual Create form enforces. Nothing is written here: rows are
 * collected so the caller can commit them all-or-nothing.
 *
 * Chunk reading keeps memory flat on large files.
 */
abstract class AbstractTableImport implements ToCollection, WithChunkReading
{
    /** Locales a template may have been downloaded in. */
    private const LOCALES = ['ar', 'en'];

    /** Resolved heading per column index. */
    private array $columnMap = [];

    /** Fields found in the uploaded heading row. */
    private array $seenFields = [];

    /** Headings the file carries that this module could not place. */
    private array $unknownHeadings = [];

    private bool $headingsRead = false;

    /** Absolute sheet row number of the last row consumed. */
    private int $rowCursor = 0;

    /** @var array<int, array{row: int, attributes: array, visits: array, followups: array}> */
    private array $rows = [];

    /** @var array<int, string> */
    private array $errors = [];

    private readonly ImportSchema $schema;

    private readonly ImportDefinition $definition;

    public function __construct(?ImportDefinition $definition = null)
    {
        $this->definition = $definition ?? ImportDefinition::get($this->moduleKey());
        $this->schema = new ImportSchema($this->definition);
    }

    /**
     * Registry key of the module this importer handles.
     */
    abstract protected function moduleKey(): string;

    public function chunkSize(): int
    {
        return 500;
    }

    public function definition(): ImportDefinition
    {
        return $this->definition;
    }

    /**
     * @return array<int, array{row: int, attributes: array, visits: array, followups: array}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $row = collect($row)->values()->all();
            $this->rowCursor++;

            if (! $this->headingsRead) {
                $this->readHeadings($row);
                $this->headingsRead = true;

                continue;
            }

            if ($this->isBlank($row) || $this->isGuidanceRow($row)) {
                continue;
            }

            $this->readRow($row, $this->rowCursor);
        }
    }

    // ---------------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------------

    private function readHeadings(array $row): void
    {
        foreach ($row as $index => $heading) {
            $resolved = $this->schema->resolveHeading(is_scalar($heading) ? (string) $heading : null);

            if ($resolved === null) {
                // An unplaceable column is not read, and that on its own is
                // right: a file may carry notes and working columns the module
                // knows nothing about. What was wrong was staying silent about
                // it. A heading typed over by accident - "Date Session Subject"
                // where the template wrote "Session Date" - failed the upload
                // as a *missing* column, which is true and useless: the column
                // is right there in the file, one word out. Remembering it lets
                // the message name it.
                $heading = trim((string) (is_scalar($heading) ? $heading : ''));

                if ($heading !== '' && ! in_array($heading, $this->unknownHeadings, true)) {
                    $this->unknownHeadings[] = $heading;
                }

                continue;
            }

            // A session past the maximum is a structural problem with the file,
            // not with one cell: name it and refuse the upload.
            if ($resolved['type'] === 'followup_overflow') {
                $message = __('fields.import_too_many_sessions', [
                    'n' => $resolved['number'],
                    'max' => $this->definition->maxFollowups(),
                ]);

                if (! in_array($message, $this->errors, true)) {
                    $this->errors[] = $message;
                }

                continue;
            }

            $this->columnMap[$index] = $resolved;

            if ($resolved['type'] === 'field') {
                $this->seenFields[] = $resolved['field'];
            }
        }
    }

    /**
     * Columns the template defines that the uploaded file does not contain.
     *
     * @return array<string>
     */
    public function missingRequiredColumns(): array
    {
        return array_values(array_diff($this->schema->requiredFields(), $this->seenFields));
    }

    /**
     * Headings present in the file that this module could not place.
     *
     * @return array<string>
     */
    public function unknownHeadings(): array
    {
        return $this->unknownHeadings;
    }

    public function hasHeadings(): bool
    {
        return $this->headingsRead && $this->columnMap !== [];
    }

    public function dataRowCount(): int
    {
        return count($this->rows);
    }

    // ---------------------------------------------------------------------
    // Rows
    // ---------------------------------------------------------------------

    private function readRow(array $row, int $rowNumber): void
    {
        $attributes = [];
        $visits = [];
        $followups = [];
        $messages = [];
        $rejected = [];

        foreach ($this->columnMap as $index => $column) {
            $value = $row[$index] ?? null;

            // Direction marks and zero-width joiners are stripped here as well
            // as in castValue(), so the repeater columns below - which go
            // straight to the date and number readers - see the same cell the
            // flat columns do.
            $value = ImportSchema::clean($value);

            if ($column['type'] === 'field') {
                $cast = $this->schema->castValue($column['field'], $value);

                if (! $cast['ok']) {
                    $messages[] = $cast['message'];
                    // Remember it, so the required check does not report the
                    // same cell a second time.
                    $rejected[] = $column['field'];

                    continue;
                }

                $attributes[$column['field']] = $cast['value'];

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $number = $column['number'];

            if (str_starts_with($column['type'], 'followup_')) {
                $followups[$number] ??= [
                    'session_number' => $number,
                    'follow_up_visit_date' => null,
                    'assess_and_analyze' => null,
                    'act' => null,
                ];

                $followups[$number][match ($column['type']) {
                    'followup_date' => 'follow_up_visit_date',
                    'followup_assess' => 'assess_and_analyze',
                    default => 'act',
                }] = $column['type'] === 'followup_date'
                    ? $this->parseDate($value, __('fields.followup_date_n', ['n' => $number]), $messages)
                    : $value;

                continue;
            }

            $visits[$number] ??= ['visit_number' => $number, 'visit_date' => null, 'muac' => null];

            if ($column['type'] === 'visit_date') {
                $visits[$number]['visit_date'] = $this->parseDate($value, __('fields.visit_date_n', ['n' => $number]), $messages);
            } else {
                $visits[$number]['muac'] = $this->parseNumber($value, __('fields.visit_muac_n', ['n' => $number]), $messages);
            }
        }

        // A blank NOT NULL boolean means "no", exactly as an unchecked toggle does.
        foreach ($this->schema->defaults() as $field => $default) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                $attributes[$field] = $default;
            }
        }

        // Recompute what the system decides for itself before validating, so a
        // derived NOT NULL column counts as filled even when the sheet left it
        // blank - and so a value the sheet did state is replaced rather than
        // trusted.
        $attributes = $this->definition->derive($attributes);

        $messages = array_merge($messages, $this->validateRow($attributes, $rejected));

        // A visit needs a date to be meaningful; drop empty placeholders.
        $visits = array_values(array_filter(
            $visits,
            fn (array $visit): bool => filled($visit['visit_date']) || filled($visit['muac']),
        ));

        // Same for a session: three blank cells are a gap in the sheet, not a
        // session that was held. Kept in column order, which is session order.
        ksort($followups);

        $followups = array_values(array_filter(
            $followups,
            fn (array $session): bool => filled($session['follow_up_visit_date'])
                || filled($session['assess_and_analyze'])
                || filled($session['act']),
        ));

        if ($messages !== []) {
            foreach ($messages as $message) {
                $this->errors[] = __('fields.import_row_error', ['row' => $rowNumber, 'message' => $message]);
            }

            return;
        }

        $this->rows[] = [
            'row' => $rowNumber,
            'attributes' => $attributes,
            'visits' => $visits,
            'followups' => $followups,
        ];
    }

    /**
     * Apply the same required/type rules the manual Create form enforces.
     *
     * @return array<string>
     */
    private function validateRow(array $attributes, array $rejected = []): array
    {
        $messages = [];

        foreach ($this->schema->requiredFields() as $field) {
            if (in_array($field, $rejected, true)) {
                continue; // Already reported as an invalid value.
            }

            if (blank($attributes[$field] ?? null)) {
                $messages[] = __('fields.import_required', ['field' => __('fields.' . $field)]);
            }
        }

        $casts = (new ($this->definition->model))->getCasts();

        foreach ($attributes as $field => $value) {
            if ($value === null || $value === '' || $value instanceof \DateTimeInterface) {
                continue;
            }

            $cast = $casts[$field] ?? null;

            if ($cast === 'integer' || $cast === 'int' || str_starts_with((string) $cast, 'decimal:')) {
                if (! is_numeric($value)) {
                    $messages[] = __('fields.import_invalid_number', [
                        'value' => ImportSchema::describe($value),
                        'field' => __('fields.' . $field),
                    ]);
                }
            }
        }

        return $messages;
    }

    /**
     * Parse one repeater date cell, reporting it under the column's own heading.
     */
    private function parseDate(mixed $value, string $label, array &$messages): mixed
    {
        try {
            return is_numeric($value)
                ? \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))->startOfDay()
                : \Carbon\Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            $messages[] = __('fields.import_invalid_date', ['field' => $label]);

            return null;
        }
    }

    private function parseNumber(mixed $value, string $label, array &$messages): mixed
    {
        if (! is_numeric($value)) {
            $messages[] = __('fields.import_invalid_number', [
                'value' => ImportSchema::describe($value),
                'field' => $label,
            ]);

            return null;
        }

        return $value;
    }

    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The template ships a grey guidance row. Skip it if it was left in place
     * rather than failing the whole upload on it.
     */
    private function isGuidanceRow(array $row): bool
    {
        static $guidance = null;

        // The row is written in whatever language the template was downloaded
        // in, which is not necessarily the language of the session uploading it
        // - a template downloaded in English and uploaded from an Arabic panel
        // was compared against the Arabic hints, matched none of them, and was
        // read as a data row, so the file failed on its own instruction line.
        // Both languages are built here for the same reason the headings are
        // resolved in both.
        $guidance ??= collect(self::LOCALES)
            ->map(function (string $locale): array {
                $original = app()->getLocale();
                app()->setLocale($locale);

                try {
                    $row = (new ImportTemplateExport($this->definition))->array()[0] ?? [];
                } finally {
                    app()->setLocale($original);
                }

                return collect($row)->map(fn ($value): string => trim((string) $value))->all();
            })
            ->all();

        $actual = collect($row)->map(fn ($value): string => trim((string) $value))->all();

        foreach ($guidance as $candidate) {
            $filled = array_filter($candidate, fn ($v) => $v !== '');

            if ($filled === []) {
                continue;
            }

            foreach ($filled as $index => $value) {
                if (($actual[$index] ?? null) !== $value) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
