<?php

namespace App\Services;

use App\Imports\AbstractTableImport;
use App\Imports\ImportDefinition;
use App\Models\FollowUpChild;
use App\Models\IndividualCounseling;
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
            return [
                'imported' => 0,
                'errors' => [__('fields.import_missing_columns', [
                    'columns' => collect($missing)->map(fn (string $f): string => __('fields.' . $f))->implode('، '),
                ])],
            ];
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

        try {
            DB::transaction(function () use ($definition, $rows): void {
                foreach ($rows as $row) {
                    try {
                        $this->createRecord($definition, $row['attributes'], $row['visits'], $row['followups'] ?? []);
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

        return ['imported' => count($rows), 'errors' => []];
    }

    /**
     * Persist one row through the model, so accessors/mutators still run and
     * derived values (FI, MUAC degree) are recalculated rather than imported.
     */
    private function createRecord(ImportDefinition $definition, array $attributes, array $visits, array $followups = []): void
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

        $model->fill($attributes);
        $model->save();

        if ($visits !== [] && $model instanceof FollowUpChild) {
            foreach ($visits as $visit) {
                $model->visits()->create([
                    'visit_number' => $visit['visit_number'],
                    'visit_date' => $visit['visit_date'],
                    'muac' => $visit['muac'],
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
