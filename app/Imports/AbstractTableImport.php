<?php

namespace App\Imports;

use App\Support\Import\ImportDateParser;
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

    /**
     * Validated rows, held on disk rather than in memory.
     *
     * One JSON object per line. Every validated row used to be kept in a PHP
     * array until the whole file had been read, so a 150,000-row upload held
     * 150,000 attribute arrays at once and the process died long before it
     * reached the database - the chunked *reading* below kept the spreadsheet
     * out of memory and then the importer put the rows straight back in.
     *
     * Written through a small buffer and read back one line at a time, so the
     * memory this costs is the buffer, whatever the size of the file.
     */
    private ?string $spillPath = null;

    /** @var resource|null */
    private $spillHandle = null;

    /** @var array<int, string> */
    private array $buffer = [];

    /** Rows written to the spill file so far. */
    private int $rowsWritten = 0;

    /** Byte position the next row will be written at. */
    private int $spillOffset = 0;

    /**
     * Where each row starts in the spill file, for the one module that has to
     * read them back in an order other than the file's own.
     *
     * Kept as reporting day => the byte offsets of that day's rows, in the
     * order the file listed them. Deliberately not a list of (day, row, offset)
     * tuples: a three-element PHP array per row costs a few hundred bytes, so
     * that shape was itself growing with the file - a tenth of the problem it
     * was there to solve, but the same shape of problem. A file spans a few
     * hundred distinct days at most, so this is a handful of keys over packed
     * lists of integers, and sorting it is a ksort over those keys rather than
     * a sort over every row.
     *
     * Rows within one day keep their file order, which is what the tie-break on
     * sheet position always did.
     *
     * @var array<string, array<int, int>>
     */
    private array $index = [];

    /** Rows held before the buffer is flushed to disk. */
    private const BUFFER_ROWS = 500;

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
     * The validated rows, one at a time, read back off the spill file.
     *
     * A generator rather than an array on purpose: the caller writes each row
     * and lets go of it, so the commit costs one row of memory instead of the
     * whole file. Rows come back in the order they were read, except for the
     * module that asked for visit order, where they come back in the order the
     * visits happened.
     *
     * @return \Generator<int, array{row: int, attributes: array, visits: array, followups: array}>
     */
    public function eachRow(): \Generator
    {
        $this->flush();

        if ($this->spillPath === null || $this->rowsWritten === 0) {
            return;
        }

        $handle = fopen($this->spillPath, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            if ($this->definition->sortsRowsByReportingDate()) {
                // A file is not always written in date order, and a July row
                // saved before a June one would have made June the "follow-up"
                // of July. Reporting days sort as "Y-m-d" strings, and each
                // day's rows are already in file order.
                $index = $this->index;
                ksort($index);

                foreach ($index as $offsets) {
                    foreach ($offsets as $offset) {
                        fseek($handle, $offset);
                        $line = fgets($handle);

                        if ($line !== false) {
                            yield json_decode($line, true);
                        }
                    }
                }

                return;
            }

            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                yield json_decode($line, true);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Release the spill file. Safe to call more than once, and called for a
     * failed import as well as a successful one.
     */
    public function discardRows(): void
    {
        if ($this->spillHandle !== null) {
            fclose($this->spillHandle);
            $this->spillHandle = null;
        }

        if ($this->spillPath !== null && is_file($this->spillPath)) {
            @unlink($this->spillPath);
        }

        $this->spillPath = null;
        $this->buffer = [];
        $this->index = [];
    }

    public function __destruct()
    {
        $this->discardRows();
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
        return $this->rowsWritten;
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

            $visits[$number] ??= ['visit_number' => $number, 'visit_date' => null, 'muac' => null, 'status' => null];

            if ($column['type'] === 'visit_date') {
                $visits[$number]['visit_date'] = $this->parseDate($value, __('fields.visit_date_n', ['n' => $number]), $messages);
            } elseif ($column['type'] === 'visit_status') {
                // Attended or missed. Only an export writes this column; a
                // sheet without it - every sheet typed by hand - records
                // attended visits, exactly as it always has.
                $cast = $this->schema->castVisitStatus($value, __('fields.visit_status_n', ['n' => $number]));

                if ($cast['ok']) {
                    $visits[$number]['status'] = $cast['value'];
                } else {
                    $messages[] = $cast['message'];
                }
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

        // Rules that hold between columns rather than inside one cell, and only
        // for the modules that have any. Run after derive() so they see the
        // values that will actually be stored.
        $messages = array_merge($messages, $this->definition->validateRow($attributes));

        // A visit is its date, exactly as the manual form now reads it: two
        // blank cells are a gap in the sheet and are dropped, a date with no
        // measurement is a visit that happened without one being taken, and a
        // measurement with no date is a reading nobody can place in time.
        //
        // The missing date is named right here rather than left to the
        // database. As a NOT NULL failure it surfaced on the first offending
        // row, rolled the whole file back and reported that one row alone - so
        // a file carrying three of them took three uploads to learn all three.
        foreach ($visits as $number => $visit) {
            if (filled($visit['visit_date'])) {
                continue;
            }

            // A missed visit is still a visit with a date - the one it was
            // due on - so a status with no date is reported like a reading
            // with no date, not dropped.
            if (! filled($visit['muac']) && blank($visit['status'] ?? null)) {
                unset($visits[$number]);

                continue;
            }

            $messages[] = __('fields.import_required', [
                'field' => __('fields.visit_date_n', ['n' => $number]),
            ]);
        }

        $visits = array_values($visits);

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

        $this->spill([
            'row' => $rowNumber,
            'attributes' => $attributes,
            'visits' => $visits,
            'followups' => $followups,
        ]);
    }

    // ---------------------------------------------------------------------
    // Validated rows, held on disk
    // ---------------------------------------------------------------------

    /**
     * Hand one validated row to the spill file.
     *
     * Dates arrive as Carbon instances and come back as "Y-m-d" strings, which
     * is what the models take anyway: every date column is cast, so the value
     * is turned back into a date on the way in. Nothing else about the row
     * survives the round trip differently.
     *
     * @param  array{row: int, attributes: array, visits: array, followups: array}  $row
     */
    private function spill(array $row): void
    {
        $row['attributes'] = $this->flatten($row['attributes']);
        $row['visits'] = array_map(fn (array $visit): array => $this->flatten($visit), $row['visits']);
        $row['followups'] = array_map(fn (array $session): array => $this->flatten($session), $row['followups']);

        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        if ($this->definition->sortsRowsByReportingDate()) {
            $this->index[(string) ($row['attributes']['date_of_reporting'] ?? '')][] = $this->spillOffset;
        }

        $this->spillOffset += strlen($line);
        $this->buffer[] = $line;
        $this->rowsWritten++;

        if (count($this->buffer) >= self::BUFFER_ROWS) {
            $this->flush();
        }
    }

    /**
     * Write the buffered rows out and let go of them.
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        if ($this->spillHandle === null) {
            $this->spillPath = tempnam(sys_get_temp_dir(), 'import-rows-');

            if ($this->spillPath === false) {
                throw new \RuntimeException('Could not open a temporary file for the import.');
            }

            $this->spillHandle = fopen($this->spillPath, 'wb');

            if ($this->spillHandle === false) {
                throw new \RuntimeException('Could not open a temporary file for the import.');
            }
        }

        fwrite($this->spillHandle, implode('', $this->buffer));

        // Pushed to the operating system rather than left in PHP's own stream
        // buffer: the rows are read back through a second handle on the same
        // file, and a final batch smaller than that buffer would still have
        // been sitting in it when the reader went looking for it.
        fflush($this->spillHandle);

        $this->buffer = [];
    }

    /**
     * Turn one row's values into something JSON can carry back unchanged.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format('Y-m-d');
            }
        }

        return $values;
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
     *
     * Read through the same parser as every flat column, and for the same
     * reason. This method used to hand the cell straight to Carbon::parse(),
     * which reads a slashed date month-first: a visit typed "03/04/2026" was
     * stored as the 4th of March while the reporting date in the very same row,
     * typed identically, was stored as the 3rd of April. Nothing said so.
     */
    private function parseDate(mixed $value, string $label, array &$messages): mixed
    {
        // A cell that states there is no date. The caller decides what an
        // absent visit date costs; guessing one here is what must not happen.
        if (ImportDateParser::statesNoDate($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return \Carbon\Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value),
                )->startOfDay();
            }

            $iso = ImportDateParser::toIsoDate($value);

            if ($iso === null) {
                throw new \InvalidArgumentException('Unreadable date cell.');
            }

            return \Carbon\Carbon::parse($iso)->startOfDay();
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
