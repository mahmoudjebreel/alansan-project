<?php

namespace App\Services;

use App\Imports\AbstractTableImport;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\IndividualCounseling;
use App\Support\Import\ChildImportVisits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Runs a module's Excel import.
 *
 * Behaviour is strict all-or-nothing: every row is validated first and, if a
 * single row is invalid, nothing at all is written and every problem is
 * reported. A valid file is committed inside one transaction.
 */
final class ExcelImportService
{
    /**
     * @return array{imported: int, errors: array<string>}
     */
    public function import(ImportDefinition $definition, string $path): array
    {
        $importerClass = $this->importerFor($definition);

        /** @var AbstractTableImport $importer */
        $importer = new $importerClass($definition);

        Excel::import($importer, $path);

        if (! $importer->hasHeadings()) {
            return ['imported' => 0, 'errors' => [__('fields.import_empty_file')]];
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

            return ['imported' => 0, 'errors' => $errors];
        }

        $errors = $importer->errors();
        $rows = $importer->rows();

        // Strict all-or-nothing: a single bad row cancels the whole file.
        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $errors];
        }

        if ($rows === []) {
            return ['imported' => 0, 'errors' => [__('fields.import_empty_file')]];
        }

        // Children rows are visits, and a visit's type depends on the visits
        // stored before it - so they are written in the order they happened,
        // whatever order the file lists them in.
        if ($definition->model === Child::class) {
            $rows = ChildImportVisits::inVisitOrder($rows);
        }

        $imported = 0;

        try {
            DB::transaction(function () use ($definition, $rows, &$imported): void {
                foreach ($rows as $row) {
                    try {
                        if ($this->createRecord($definition, $row['attributes'], $row['visits'], $row['followups'] ?? [])) {
                            $imported++;
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
        } catch (RowImportException $e) {
            // The transaction has already rolled back: nothing was written.
            return ['imported' => 0, 'errors' => [$e->getMessage()]];
        }

        return ['imported' => $imported, 'errors' => []];
    }

    /**
     * Persist one row through the model, so accessors/mutators still run and
     * derived values (FI, MUAC degree) are recalculated rather than imported.
     *
     * Returns false for a row that was not written because the visit it
     * describes is already in the system.
     */
    private function createRecord(ImportDefinition $definition, array $attributes, array $visits, array $followups = []): bool
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
            // The same file uploaded twice must not store every visit twice.
            if (ChildImportVisits::alreadyStored($attributes)) {
                return false;
            }

            // Settled here, once the earlier visits of this file are stored,
            // rather than at read time when none of them were yet.
            $attributes['visit_type'] = ChildImportVisits::visitType($attributes);
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

        return true;
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
