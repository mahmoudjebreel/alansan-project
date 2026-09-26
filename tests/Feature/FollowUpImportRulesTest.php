<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * F12: a child has one open Follow-Up episode at a time - an upload cannot add
 * a new episode for a child whose episode is open, on file or in the same file.
 *
 * F9-B: a death recorded in the same upload refuses a row dated after it,
 * exactly as a death already on file does.
 *
 * The upload is all-or-nothing: a refused row refuses the file.
 */
class FollowUpImportRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // =================================================================
    // F12 - one open episode per child
    // =================================================================

    public function test_a_new_row_for_a_child_with_an_open_episode_on_file_is_refused(): void
    {
        $this->stored('470200001', ['admission_date' => '2026-06-01']);

        $result = $this->import([
            $this->row('470200001', '2026-09-01'),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.follow_up_import.open_on_file', ['id' => '470200001']), implode(' ', $result['errors']));
        $this->assertSame(1, FollowUpChild::where('id_number', '470200001')->count());
    }

    public function test_a_child_with_only_closed_episodes_on_file_can_be_given_a_new_one(): void
    {
        $this->stored('470200002', ['admission_date' => '2026-06-01', 'discharge_date' => '2026-06-20', 'discharge_outcome' => 'defaulted']);

        $result = $this->import([
            $this->row('470200002', '2026-09-01'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, FollowUpChild::where('id_number', '470200002')->count());
    }

    public function test_a_valid_new_child_imports(): void
    {
        $result = $this->import([
            $this->row('470200003', '2026-09-01'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }

    public function test_two_open_rows_for_one_child_in_the_same_file_are_refused(): void
    {
        $result = $this->import([
            $this->row('470200004', '2026-08-01'),
            $this->row('470200004', '2026-09-01'),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.follow_up_import.open_in_file', ['id' => '470200004']), implode(' ', $result['errors']));
        $this->assertSame(0, FollowUpChild::where('id_number', '470200004')->count());
    }

    public function test_an_episode_after_an_open_row_in_the_same_file_is_refused_whatever_the_row_order(): void
    {
        // The later, closed row listed first; the open row after it.
        $result = $this->import([
            $this->row('470200005', '2026-09-01', 'cured', '2026-09-20'),
            $this->row('470200005', '2026-08-01'),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.follow_up_import.open_in_file', ['id' => '470200005']), implode(' ', $result['errors']));
    }

    public function test_closed_history_before_an_open_row_in_the_same_file_imports(): void
    {
        $result = $this->import([
            $this->row('470200006', '2026-05-01', 'defaulted', '2026-05-20'),
            $this->row('470200006', '2026-08-01'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);
    }

    public function test_re_uploading_the_open_episode_itself_is_not_refused(): void
    {
        // C2: a file uploaded again carries the episodes already on file; the
        // open episode is not a new one, and the duplicate guard skips it.
        $this->import([$this->row('470200007', '2026-08-01')]);

        $again = $this->import([$this->row('470200007', '2026-08-01')]);

        $this->assertSame([], $again['errors']);
        $this->assertSame(1, FollowUpChild::where('id_number', '470200007')->count());
    }

    // =================================================================
    // F9-B - a death in the same file
    // =================================================================

    public function test_a_row_dated_after_a_death_in_the_same_file_is_refused(): void
    {
        $result = $this->import([
            $this->row('470200010', '2026-05-01', 'died', '2026-05-20'),
            $this->row('470200010', '2026-08-01', 'cured', '2026-08-20'),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.follow_up_import.died_in_file', ['id' => '470200010']), implode(' ', $result['errors']));
        $this->assertSame(0, FollowUpChild::where('id_number', '470200010')->count());
    }

    public function test_a_death_listed_after_the_later_row_is_refused_too(): void
    {
        $result = $this->import([
            $this->row('470200011', '2026-08-01', 'cured', '2026-08-20'),
            $this->row('470200011', '2026-05-01', 'died', '2026-05-20'),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.follow_up_import.died_in_file', ['id' => '470200011']), implode(' ', $result['errors']));
    }

    public function test_history_before_and_on_the_death_in_the_same_file_imports(): void
    {
        // F9-A unchanged: before the death, and on its date, is history.
        $result = $this->import([
            $this->row('470200012', '2026-03-01', 'defaulted', '2026-03-20'),
            $this->row('470200012', '2026-05-01', 'died', '2026-05-20'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);
    }

    public function test_each_upload_starts_from_nothing(): void
    {
        // What one upload saw does not follow into the next one.
        $this->import([$this->row('470200013', '2026-05-01', 'defaulted', '2026-05-20')]);

        $result = $this->import([$this->row('470200013', '2026-08-01', 'cured', '2026-08-20')]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, FollowUpChild::where('id_number', '470200013')->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function stored(string $idNumber, array $attributes): FollowUpChild
    {
        $episode = FollowUpChild::create(array_merge([
            'id_number' => $idNumber,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ], $attributes));

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => $attributes['admission_date'], 'muac' => 110]);

        return $episode;
    }

    /** @return array<string, mixed> heading => value */
    private function row(string $idNumber, string $admitted, ?string $outcome = null, ?string $discharged = null): array
    {
        $values = [
            __('fields.id_number') => $idNumber,
            __('fields.child_name') => 'Test child',
            __('fields.governorate') => 'Gaza',
            __('fields.admission_date') => $admitted,
            __('fields.visit_date_n', ['n' => 1]) => $admitted,
            __('fields.visit_muac_n', ['n' => 1]) => 110,
        ];

        if ($outcome !== null) {
            $values[__('fields.discharge_outcome')] = __('fields.' . $outcome);
            $values[__('fields.discharge_date')] = $discharged;
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, errors: array<string>, skipped: array<string>}
     */
    private function import(array $rows): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('follow_up_children')))->headings();
        $sheet = [$headings];

        foreach ($rows as $values) {
            $row = array_fill(0, count($headings), null);

            foreach ($values as $heading => $value) {
                $index = array_search($heading, $headings, true);
                $this->assertNotFalse($index, "No [{$heading}] column.");
                $row[$index] = $value;
            }

            $sheet[] = $row;
        }

        $export = new class($sheet) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'rules-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get('follow_up_children'),
            Storage::disk('local')->path($name),
        );
    }
}
