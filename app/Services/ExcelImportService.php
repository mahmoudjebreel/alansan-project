<?php

namespace App\Services;

use App\Imports\AbstractTableImport;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Support\GroupSessionDuplicateChecker;
use App\Support\Import\ChildImportVisits;
use App\Support\Import\ImportDuplicateGuard;
use App\Support\MotherToMotherDuplicateChecker;
use App\Support\PregnantWomanDuplicateChecker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Runs a module's Excel import.
 *
 * Behaviour is strict all-or-nothing: every row is validated first and, if a
 * single row is invalid, nothing at all is written and every problem is
 * reported. A valid file is committed inside one transaction.
 *
 * A duplicate is the one thing that does not cancel the file. It is not an
 * invalid row - it is a row describing a visit the system already holds - and
 * refusing the upload over it would make the ordinary working habit impossible:
 * the teams append the new month to last month's file and upload the whole
 * thing again. So a duplicate is skipped and named, row by row, in the
 * 'skipped' half of the result, and the rows around it import.
 */
final class ExcelImportService
{
    /**
     * @return array{imported: int, errors: array<string>, skipped: array<string>}
     */
    public function import(ImportDefinition $definition, string $path): array
    {
        $importerClass = $this->importerFor($definition);

        /** @var AbstractTableImport $importer */
        $importer = new $importerClass($definition);

        // The audit summary states when the import ran and how long it took.
        // Both come from the clock of the request that is already running, so
        // no table and no column is needed to hold them - they ride along in
        // the activity entry's own properties, like every other field below.
        $startedAt = \Carbon\CarbonImmutable::now();
        $startedTimer = microtime(true);

        Excel::import($importer, $path);

        if (! $importer->hasHeadings()) {
            return $this->finish(
                $definition, $path, $startedAt, $startedTimer, $importer,
                imported: 0,
                skipped: [],
                errors: [__('fields.import_empty_file')],
            );
        }

        $missing = $importer->missingRequiredColumns();

        if ($missing !== []) {
            $errors = [__('fields.import_missing_columns', [
                'columns' => collect($missing)->map(fn (string $f): string => __('fields.' . $f))->implode('، '),
            ])];

            // Naming a column as missing is only half an answer when the column
            // is in the file under a heading nobody recognised. Listing what
            // was not understood alongside it turns "Session Date is missing"
            // into something the uploader can act on, because the mistyped
            // heading is sitting right there in the same message.
            $unknown = $importer->unknownHeadings();

            if ($unknown !== []) {
                $errors[] = __('fields.import_unknown_columns', [
                    'columns' => collect($unknown)->implode('، '),
                ]);
            }

            return $this->finish(
                $definition, $path, $startedAt, $startedTimer, $importer,
                imported: 0,
                skipped: [],
                errors: $errors,
            );
        }

        $errors = $importer->errors();

        // Strict all-or-nothing: a single bad row cancels the whole file.
        if ($errors !== []) {
            $importer->discardRows();

            return $this->finish(
                $definition, $path, $startedAt, $startedTimer, $importer,
                imported: 0,
                skipped: [],
                errors: $errors,
            );
        }

        if ($importer->dataRowCount() === 0) {
            $importer->discardRows();

            return $this->finish(
                $definition, $path, $startedAt, $startedTimer, $importer,
                imported: 0,
                skipped: [],
                errors: [__('fields.import_empty_file')],
            );
        }

        $imported = 0;
        $skipped = [];
        $writtenKeys = [];

        try {
            // One transaction still, because the whole file has already been
            // validated by this point: a rollback here is an unexpected
            // database failure, not a bad row, and half a file written in that
            // case is worse than none. What has changed is that the rows are
            // no longer all in memory while it runs - they are streamed off
            // disk one at a time.
            //
            // The per-row activity entries are held back for the duration.
            // Spatie writes one INSERT carrying the whole row as JSON for every
            // record saved, which on a large file is a second copy of the
            // import inside the same transaction. One entry naming the module
            // and the count replaces them, exactly as BulkRecordWriter already
            // does for a bulk delete. Model events still fire, so nothing the
            // records themselves derive is affected.
            activity()->withoutLogs(function () use ($importer, $definition, &$imported, &$skipped, &$writtenKeys): void {
                DB::transaction(function () use ($importer, $definition, &$imported, &$skipped, &$writtenKeys): void {
                    foreach ($importer->eachRow() as $row) {
                        try {
                            $duplicate = ImportDuplicateGuard::reason($definition, $row['attributes'], $row['visits']);

                            if ($duplicate !== null) {
                                // Reported, never silent - but not fatal
                                // either. A file re-uploaded with one more
                                // month appended has to import that month, and
                                // refusing the whole upload over the rows that
                                // were already in the system would make that
                                // impossible.
                                $skipped[] = __('fields.import_row_error', [
                                    'row' => $row['row'],
                                    'message' => $duplicate,
                                ]);

                                continue;
                            }

                            $record = $this->createRecord(
                                $definition,
                                $row['attributes'],
                                $row['visits'],
                                $row['followups'] ?? [],
                            );

                            if ($record !== null) {
                                $imported++;

                                if (count($writtenKeys) < 20) {
                                    $writtenKeys[] = $record->getKey();
                                }
                            }
                        } catch (\Illuminate\Database\QueryException $e) {
                            // Surface the offending row instead of a raw SQL dump.
                            throw new RowImportException(
                                __('fields.import_row_error', [
                                    'row' => $row['row'],
                                    'message' => $this->summarise($e),
                                ]),
                                previous: $e,
                            );
                        }
                    }
                });
            });
        } catch (RowImportException $e) {
            // The transaction has already rolled back: nothing was written.
            return $this->finish(
                $definition, $path, $startedAt, $startedTimer, $importer,
                imported: 0,
                skipped: [],
                errors: [$e->getMessage()],
            );
        } finally {
            $importer->discardRows();
        }

        return $this->finish(
            $definition, $path, $startedAt, $startedTimer, $importer,
            imported: $imported,
            skipped: $skipped,
            errors: [],
            sampleKeys: $writtenKeys,
        );
    }

    /**
     * Close one import: write its audit entry and return its result.
     *
     * Every way out of import() comes through here, which is the point. The
     * summary used to be written on the success path alone and only when at
     * least one row was stored, so the two runs an auditor most wants to find -
     * a file that was refused outright, and a file every row of which was
     * already in the system - left no trace at all. A run that did nothing is
     * still a run somebody performed.
     *
     * @param  array<int, string>  $skipped
     * @param  array<int, string>  $errors
     * @param  array<int, mixed>  $sampleKeys
     * @return array{imported: int, errors: array<string>, skipped: array<string>}
     */
    private function finish(
        ImportDefinition $definition,
        string $path,
        \Carbon\CarbonImmutable $startedAt,
        float $startedTimer,
        AbstractTableImport $importer,
        int $imported,
        array $skipped,
        array $errors,
        array $sampleKeys = [],
    ): array {
        $this->recordSummary(
            definition: $definition,
            path: $path,
            startedAt: $startedAt,
            startedTimer: $startedTimer,
            importer: $importer,
            imported: $imported,
            skipped: count($skipped),
            errors: $errors,
            sampleKeys: $sampleKeys,
        );

        return ['imported' => $imported, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * One activity entry for the whole import, in place of one per row.
     *
     * The same log and the same shape BulkRecordWriter writes for a bulk
     * delete, and for the same reason: the entry names the module and the
     * counts, because there is no single subject, and the sample of IDs is what
     * lets an operator find the affected rows afterwards.
     *
     * What it says is the whole run: who ran it, on which module, from which
     * file, when, for how long, how many rows the file held, how many were
     * stored, how many were already in the system, how many were refused, and
     * how it ended. All of that goes into the properties column the activity
     * log already has - no new table, no new column, and nothing derived that
     * could be wrong: the row counts come from the importer's own tallies.
     *
     * Writing the entry can never cancel an import. It runs after the
     * transaction has closed, and a failure to audit is logged and swallowed
     * rather than thrown - losing the record of an import is bad, losing the
     * import itself over it would be worse.
     *
     * @param  array<int, string>  $errors
     * @param  array<int, mixed>  $sampleKeys
     */
    private function recordSummary(
        ImportDefinition $definition,
        string $path,
        \Carbon\CarbonImmutable $startedAt,
        float $startedTimer,
        AbstractTableImport $importer,
        int $imported,
        int $skipped,
        array $errors,
        array $sampleKeys,
    ): void {
        $module = class_basename($definition->model);

        // Failed: nothing was stored and something was wrong with the file.
        // Success with duplicates: rows were stored and rows were recognised as
        // already present. Success: everything the file offered was taken.
        $status = match (true) {
            $errors !== [] => 'failed',
            $skipped > 0 => 'success_with_duplicates',
            default => 'success',
        };

        try {
            activity()
                ->useLog('bulk')
                ->causedBy(auth()->user())
                ->withProperties([
                    'module' => $module,
                    'action' => 'import',
                    'status' => $status,
                    // The name of the uploaded file as it was stored. The
                    // directory is left out: it is the same for every import
                    // and it is a server path, which an audit entry has no
                    // business carrying.
                    'file' => basename($path),
                    'total_rows' => $importer->totalRowCount(),
                    'imported_rows' => $imported,
                    'duplicate_rows' => $skipped,
                    'rejected_rows' => $importer->rejectedRowCount(),
                    'error_count' => count($errors),
                    'started_at' => $startedAt->toIso8601String(),
                    'finished_at' => \Carbon\CarbonImmutable::now()->toIso8601String(),
                    'duration_ms' => (int) round((microtime(true) - $startedTimer) * 1000),
                    'sample_ids' => array_slice($sampleKeys, 0, 20),
                    // 'count' is what BulkRecordWriter's entries carry and what
                    // anything reading the bulk log already looks for. Kept so
                    // the richer entry stays readable to it.
                    'count' => $imported,
                ])
                ->log("{$module} bulk import ({$imported})");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Import audit entry could not be written: ' . $e->getMessage(),
                ['exception' => $e, 'module' => $module],
            );
        }
    }

    /**
     * Persist one row through the model, so accessors/mutators still run and
     * derived values (FI, MUAC degree) are recalculated rather than imported.
     *
     * Whether the row is a duplicate is settled before this is called, by
     * ImportDuplicateGuard - it used to be answered halfway down this method
     * for Children and nowhere at all for the other five modules, and a row it
     * turned down simply vanished without a word.
     */
    private function createRecord(ImportDefinition $definition, array $attributes, array $visits, array $followups = []): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition->model;

        $model = new $modelClass();

        // Blank cells are omitted rather than written as NULL, so each column's
        // own default still applies (e.g. an unchecked boolean stays 0).
        $attributes = array_filter(
            array_intersect_key($attributes, array_flip($model->getFillable())),
            static fn (mixed $value): bool => $value !== null,
        );

        if ($model instanceof Child) {
            // Settled here, once the earlier visits of this file are stored,
            // rather than at read time when none of them were yet.
            $attributes['visit_type'] = ChildImportVisits::visitType($attributes);
        }

        if ($model instanceof PregnantLactatingWoman) {
            // Same reason as above: the status rule compares this visit with
            // the mother's latest stored one, and the earlier rows of this
            // file are stored by now. Decided at read time only, a "pregnant"
            // row under a "pregnant + lactating" row of the same file was
            // compared with the record from before the upload instead.
            $attributes['visit_type'] = PregnantWomanDuplicateChecker::resolveVisitType(
                $attributes['mother_id'] ?? null,
                $attributes['status_type'] ?? null,
            );
        }

        if ($model instanceof GroupSession) {
            // Same reason once more: a participant already registered is
            // attending a follow-up. Read time is too early to ask - the
            // module's deriver runs before anything is written, so every row
            // of a bulk upload was compared against a table that did not yet
            // hold the rows above it and came out "new".
            $attributes['visit_type'] = GroupSessionDuplicateChecker::resolveVisitType(
                $attributes['id_number'] ?? null,
            );
        }

        if ($model instanceof MotherToMotherSession) {
            // The mother-to-mother module counts attendance the same way its
            // twin does, so it is settled here for the same reason: the rows
            // above this one in the file are stored by now.
            $attributes['visit_type'] = MotherToMotherDuplicateChecker::resolveVisitType(
                $attributes['id_number'] ?? null,
            );
        }

        $model->fill($attributes);
        $model->save();

        if ($visits !== [] && $model instanceof FollowUpChild) {
            foreach ($visits as $visit) {
                $model->visits()->create([
                    'visit_number' => $visit['visit_number'],
                    'visit_date' => $visit['visit_date'],
                    'muac' => $visit['muac'],
                    // Attended unless the sheet says missed; the visit model
                    // clears the reading of a missed one itself.
                    'status' => $visit['status'] ?? FollowUpChildVisit::STATUS_ATTENDED,
                ]);
            }
        }

        // Numbered session columns become one related row each, never flat
        // columns on the record. sort_order is the position the sessions were
        // read in, which is what the form and the export both number from.
        if ($followups !== [] && $model instanceof IndividualCounseling) {
            foreach (array_values($followups) as $position => $session) {
                $model->followups()->create([
                    'sort_order' => $position + 1,
                    'follow_up_visit_date' => $session['follow_up_visit_date'],
                    'assess_and_analyze' => $session['assess_and_analyze'],
                    'act' => $session['act'],
                ]);
            }
        }

        return $model;
    }

    /**
     * Condense a database error into something an admin can act on.
     */
    private function summarise(\Illuminate\Database\QueryException $e): string
    {
        if (preg_match('/NOT NULL constraint failed: \w+\.(\w+)/', $e->getMessage(), $m)
            || preg_match("/Column '(\w+)' cannot be null/", $e->getMessage(), $m)) {
            return __('fields.import_required', ['field' => __('fields.' . $m[1])]);
        }

        return $e->getMessage();
    }

    /**
     * Locate the module's Import class by convention.
     *
     * @return class-string<AbstractTableImport>
     */
    private function importerFor(ImportDefinition $definition): string
    {
        return match ($definition->key) {
            'children' => \App\Imports\ChildrenImport::class,
            'pregnant' => \App\Imports\PregnantWomenImport::class,
            'group_sessions' => \App\Imports\GroupSessionImport::class,
            'mother_to_mother' => \App\Imports\MotherToMotherImport::class,
            'individual_counseling' => \App\Imports\IndividualCounselingImport::class,
            'follow_up_children' => \App\Imports\FollowUpChildImport::class,
            default => throw new \InvalidArgumentException("No importer for [{$definition->key}]."),
        };
    }
}
