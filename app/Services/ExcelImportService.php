<?php

namespace App\Services;

use App\Imports\AbstractTableImport;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\PregnantLactatingWoman;
use App\Support\ChildDuplicateChecker;
use App\Support\GroupSessionDuplicateChecker;
use App\Support\PregnantWomanDuplicateChecker;
use Carbon\Carbon;
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

        // Whatever the file said about a derived column is discarded here, the
        // same way the Create form derives it rather than accepting it.
        $this->applyDerivedValues($model);

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
     * Re-derive the values the system owns, so an uploaded cell can never set
     * them. Each module is handled entirely on its own terms: nothing here is
     * shared between them beyond the dispatch itself.
     */
    private function applyDerivedValues(Model $model): void
    {
        match (true) {
            $model instanceof Child => $this->deriveChild($model),
            $model instanceof PregnantLactatingWoman => $this->derivePregnantLactatingWoman($model),
            $model instanceof GroupSession => $this->deriveGroupSession($model),
            $model instanceof IndividualCounseling => $this->deriveIndividualCounseling($model),
            default => null,
        };
    }

    /**
     * Children: age in months from the date of birth, and the locked visit type
     * from the relapse rule (this MUAC's FI against the last active visit's).
     * FI itself is already re-derived by the model's MUAC mutator.
     */
    private function deriveChild(Child $model): void
    {
        if (filled($model->date_of_birth)) {
            $model->age_months = $this->monthsSince($model->date_of_birth);
        }

        $model->visit_type = ChildDuplicateChecker::resolveVisitType(
            $model->child_id,
            $model->muac_mm,
        );
    }

    /**
     * Pregnant / lactating women: age in years from the date of birth, and the
     * locked visit type from the pregnant/lactating switch against the last
     * active visit.
     */
    private function derivePregnantLactatingWoman(PregnantLactatingWoman $model): void
    {
        if (filled($model->date_of_birth)) {
            $model->age_years = $this->yearsSince($model->date_of_birth);
        }

        $model->visit_type = PregnantWomanDuplicateChecker::resolveVisitType(
            $model->mother_id,
            $model->status_type,
        );
    }

    /**
     * Group sessions: the locked visit type is simply whether this ID number
     * already has an active session. There is no MUAC here, so no relapse rule.
     */
    private function deriveGroupSession(GroupSession $model): void
    {
        $model->visit_type = GroupSessionDuplicateChecker::resolveVisitType($model->id_number);
    }

    /**
     * Individual counseling: both ages come from their dates of birth. The two
     * visit-type columns are programme fields chosen per session, not derived,
     * so they are left exactly as the file supplied them.
     */
    private function deriveIndividualCounseling(IndividualCounseling $model): void
    {
        if (filled($model->child_dob)) {
            $model->age_months = IndividualCounseling::ageInMonths($model->child_dob);
        }

        if (filled($model->mother_dob)) {
            $model->mother_age_years = IndividualCounseling::ageInYears($model->mother_dob);
        }
    }

    private function monthsSince(mixed $date): int
    {
        return (int) Carbon::parse($date)->diffInMonths(Carbon::now());
    }

    private function yearsSince(mixed $date): int
    {
        return (int) Carbon::parse($date)->diffInYears(Carbon::now());
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
