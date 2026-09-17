<?php

namespace Tests\Feature;

use App\Exports\FollowUpChildrenExport;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\ImportSchema;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Follow Up Children export: every episode on file, streamed as CSV,
 * with the readmission history and the ages readable off the sheet.
 *
 * The export used to read the listing's own query, so with the Active tab
 * open it wrote the open cases and nothing else. It now writes the whole
 * history whatever the tab shows, a chunk at a time, in primary-key order.
 * Nothing here changes how an episode is classified: the classification is
 * read from the closed episode a readmission is linked to, through the
 * model's own relation, exactly as the module decided it.
 */
class FollowUpChildrenExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Every episode is exported, whatever became of it
    // -----------------------------------------------------------------

    public function test_every_outcome_is_exported_including_historical_and_open_episodes(): void
    {
        $outcomes = [
            FollowUpChild::ACTIVE_OUTCOME, 'cured', 'defaulted', 'non_responded',
            'referred_medical_inpt', 'discharge_to_opt', 'discharge_to_other', 'died',
        ];

        $ids = [];

        foreach ($outcomes as $i => $outcome) {
            $ids[] = $this->episode([
                'id_number' => '60000000' . ($i + 1),
                'admission_date' => '2024-0' . ($i + 1) . '-01',
                'discharge_date' => $outcome === FollowUpChild::ACTIVE_OUTCOME ? null : '2024-0' . ($i + 1) . '-20',
                'discharge_outcome' => $outcome,
            ])->id_number;
        }

        // A row with no outcome recorded at all is open too, and exported.
        $ids[] = $this->episode(['id_number' => '600000099', 'discharge_outcome' => null])->id_number;

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $column = $this->column($headings, 'id_number');

        $this->assertCount(count($ids), $rows);
        $this->assertEqualsCanonicalizing($ids, array_column($rows, $column));
    }

    public function test_the_original_episode_and_its_readmission_are_two_rows(): void
    {
        $previous = $this->closedEpisode('SAM', 'defaulted', '600000010');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $this->assertNotNull($readmission);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $this->assertCount(2, $rows);

        $type = $this->column($headings, 'admission_type');
        $outcome = $this->column($headings, 'discharge_outcome');

        $original = $this->row($headings, $rows, $previous);
        $returned = $this->row($headings, $rows, $readmission);

        $this->assertSame('', $original[$type]);
        $this->assertSame(__('fields.defaulted'), $original[$outcome]);
        $this->assertSame(__('fields.readmission'), $returned[$type]);
        $this->assertSame(__('fields.under_follow_up'), $returned[$outcome]);
    }

    // -----------------------------------------------------------------
    // Readmission history: the three classifications, read from the link
    // -----------------------------------------------------------------

    public function test_a_readmission_after_defaulted_carries_its_classification_and_previous_episode(): void
    {
        $previous = $this->closedEpisode('SAM', 'defaulted', '600000011');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $original = $this->row($headings, $rows, $previous);
        $returned = $this->row($headings, $rows, $readmission);

        $this->assertSame(__('fields.readmission_after_defaulted'), $returned[$this->column($headings, 'readmission_classification')]);
        $this->assertSame('2026-08-01', $returned[$this->column($headings, 'previous_episode_admission_date')]);
        $this->assertSame('2026-08-20', $returned[$this->column($headings, 'previous_episode_discharge_date')]);
        $this->assertSame(__('fields.defaulted'), $returned[$this->column($headings, 'previous_episode_outcome')]);

        // The original episode follows nothing, so its history columns are blank.
        foreach (FollowUpChildrenExport::COMPUTED_FIELDS as $field) {
            if ($field !== 'age_at_last_visit') {
                $this->assertSame('', $original[$this->column($headings, $field)], "[{$field}] must be blank on a first admission.");
            }
        }
    }

    public function test_a_readmission_after_an_other_exit_is_classified_as_after_other(): void
    {
        foreach (['discharge_to_opt', 'discharge_to_other', 'referred_medical_inpt'] as $i => $outcome) {
            $previous = $this->closedEpisode('MAM', $outcome, '60000002' . $i);

            $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
                'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 118,
            ]);

            [$headings, $rows] = $this->csv(FollowUpChild::query()->where('id_number', $previous->id_number));

            $returned = $this->row($headings, $rows, $readmission);

            $this->assertSame(__('fields.readmission_after_other'), $returned[$this->column($headings, 'readmission_classification')], "[{$outcome}]");
            $this->assertSame(__('fields.' . $outcome), $returned[$this->column($headings, 'previous_episode_outcome')]);
        }
    }

    public function test_a_return_after_a_cured_sam_mam_episode_is_exported_as_readmission_after_relapse(): void
    {
        Carbon::setTestNow('2026-09-05');

        $cured = $this->closedEpisode('SAM', 'cured', '600000030');

        $relapse = ChildFollowUpTransfer::refer($this->screening('600000030', 110));

        $this->assertNotNull($relapse);
        $this->assertSame($cured->getKey(), $relapse->previous_follow_up_child_id);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $returned = $this->row($headings, $rows, $relapse);

        $this->assertSame(__('fields.readmission_after_relapse'), $returned[$this->column($headings, 'readmission_classification')]);
        $this->assertSame('2026-08-01', $returned[$this->column($headings, 'previous_episode_admission_date')]);
        $this->assertSame('2026-08-20', $returned[$this->column($headings, 'previous_episode_discharge_date')]);
        $this->assertSame(__('fields.cured'), $returned[$this->column($headings, 'previous_episode_outcome')]);
    }

    public function test_the_classification_is_never_inferred_from_the_child_id_alone(): void
    {
        // Two episodes for one child with no link between them: a row written
        // before the link existed. The export says nothing it does not know.
        $this->episode([
            'id_number' => '600000040', 'admission_date' => '2026-05-01',
            'discharge_date' => '2026-05-20', 'discharge_outcome' => 'defaulted',
        ]);
        $unlinked = $this->episode([
            'id_number' => '600000040', 'admission_date' => '2026-09-01',
            'admission_type' => FollowUpChild::ADMISSION_READMISSION,
        ]);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $row = $this->row($headings, $rows, $unlinked);

        $this->assertSame(__('fields.readmission'), $row[$this->column($headings, 'admission_type')]);
        $this->assertSame('', $row[$this->column($headings, 'readmission_classification')]);
        $this->assertSame('', $row[$this->column($headings, 'previous_episode_admission_date')]);
        $this->assertSame('', $row[$this->column($headings, 'previous_episode_outcome')]);
    }

    public function test_a_previous_episode_that_ended_as_died_or_non_responded_classifies_nothing(): void
    {
        foreach (['died', 'non_responded'] as $i => $outcome) {
            $previous = $this->closedEpisode('SAM', $outcome, '60000005' . $i);

            // Linked by hand, as no workflow would: the link is there, the
            // classification still is not.
            $episode = $this->episode([
                'id_number' => $previous->id_number, 'admission_date' => '2026-09-01',
                'previous_follow_up_child_id' => $previous->getKey(),
            ]);

            [$headings, $rows] = $this->csv(FollowUpChild::query()->where('id_number', $previous->id_number));

            $row = $this->row($headings, $rows, $episode);

            $this->assertSame('', $row[$this->column($headings, 'readmission_classification')], "[{$outcome}]");
            $this->assertSame(__('fields.' . $outcome), $row[$this->column($headings, 'previous_episode_outcome')]);
        }
    }

    public function test_a_soft_deleted_previous_episode_still_resolves(): void
    {
        $previous = $this->closedEpisode('SAM', 'defaulted', '600000060');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $previous->delete();

        $this->assertSoftDeleted($previous);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        // The trashed episode itself is not exported; the readmission is,
        // and still knows what it followed.
        $this->assertCount(1, $rows);

        $returned = $this->row($headings, $rows, $readmission);

        $this->assertSame(__('fields.readmission_after_defaulted'), $returned[$this->column($headings, 'readmission_classification')]);
        $this->assertSame('2026-08-20', $returned[$this->column($headings, 'previous_episode_discharge_date')]);
    }

    // -----------------------------------------------------------------
    // The active tab does not restrict the export
    // -----------------------------------------------------------------

    public function test_the_active_tab_does_not_restrict_the_export(): void
    {
        $this->episode(['id_number' => '600000070', 'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME]);
        $this->episode(['id_number' => '600000071', 'discharge_date' => '2026-06-20', 'discharge_outcome' => 'cured']);
        $this->episode(['id_number' => '600000072', 'discharge_date' => '2026-06-20', 'discharge_outcome' => 'defaulted']);

        $page = Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active']);

        // The listing itself shows one row.
        $this->assertSame(1, $page->instance()->getTableQueryForExport()->count());

        // The export query sees all three.
        $this->assertSame(3, $page->instance()->allHistoryExportQuery()->count());

        $page->call('downloadExcel')->assertFileDownloaded('follow-up-children.csv');

        $content = base64_decode(data_get($page->effects, 'download.content'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        [, $rows] = $this->parse($content);

        $this->assertCount(3, $rows);
    }

    public function test_the_closed_tab_does_not_restrict_the_export_either(): void
    {
        $this->episode(['id_number' => '600000073', 'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME]);
        $this->episode(['id_number' => '600000074', 'discharge_date' => '2026-06-20', 'discharge_outcome' => 'cured']);

        $page = Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed']);

        $this->assertSame(2, $page->instance()->allHistoryExportQuery()->count());
    }

    public function test_the_export_requires_the_export_permission(): void
    {
        // Mounted with the permission, called without it: the check on the
        // method itself still refuses, whatever the page let through.
        $page = Livewire::test(ListFollowUpChildren::class)->instance();

        $this->actingAs(User::factory()->create());

        try {
            $page->downloadExcel();
            $this->fail('The export must refuse a user without the permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // Chunked streaming: no row lost or repeated at a page boundary
    // -----------------------------------------------------------------

    public function test_identical_admission_dates_across_a_chunk_boundary_neither_skip_nor_repeat_rows(): void
    {
        $count = FollowUpChildrenExport::CSV_CHUNK + 3;

        for ($i = 1; $i <= $count; $i++) {
            $this->episode([
                'id_number' => (string) (700000000 + $i),
                'admission_date' => '2026-03-01',
            ]);
        }

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $ids = array_column($rows, $this->column($headings, 'id_number'));

        $this->assertCount($count, $rows);
        $this->assertCount($count, array_unique($ids));
    }

    // -----------------------------------------------------------------
    // Export values
    // -----------------------------------------------------------------

    public function test_sam_and_mam_export_as_the_bare_acronyms(): void
    {
        $sam = $this->episode(['id_number' => '600000080', 'admitted_with' => 'SAM']);
        $mam = $this->episode(['id_number' => '600000081', 'admitted_with' => 'MAM']);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $column = $this->column($headings, 'admitted_with');

        $this->assertSame('SAM', $this->row($headings, $rows, $sam)[$column]);
        $this->assertSame('MAM', $this->row($headings, $rows, $mam)[$column]);

        foreach (['en', 'ar'] as $locale) {
            $this->assertSame('SAM', trans('fields.SAM', [], $locale));
            $this->assertSame('MAM', trans('fields.MAM', [], $locale));
        }

        foreach ($rows as $row) {
            $this->assertNotContains('fields.SAM', $row);
            $this->assertNotContains('fields.MAM', $row);
        }
    }

    public function test_the_existing_enum_labels_are_unchanged(): void
    {
        $episode = $this->episode([
            'id_number' => '600000082', 'sex' => 'F', 'admission_type' => FollowUpChild::ADMISSION_READMISSION,
            'discharge_date' => '2026-06-20', 'discharge_outcome' => 'referred_medical_inpt',
        ]);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $row = $this->row($headings, $rows, $episode);

        $this->assertSame(__('fields.F'), $row[$this->column($headings, 'sex')]);
        $this->assertSame(__('fields.readmission'), $row[$this->column($headings, 'admission_type')]);
        $this->assertSame(__('fields.referred_medical_inpt'), $row[$this->column($headings, 'discharge_outcome')]);
    }

    // -----------------------------------------------------------------
    // Age: at admission as before, at the last visit from the visit's date
    // -----------------------------------------------------------------

    public function test_age_at_admission_and_age_at_last_visit_are_taken_from_the_dates_not_from_today(): void
    {
        // Long after every date on the record, so "today" cannot be right.
        Carbon::setTestNow('2031-01-01');

        $episode = $this->episode([
            'id_number' => '600000090', 'dob' => '2024-11-19', 'admission_date' => '2026-08-19',
        ]);
        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110]);
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => 112]);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $row = $this->row($headings, $rows, $episode);

        $this->assertSame(
            FollowUpChild::formatAgeAtAdmission('2024-11-19', '2026-08-19'),
            $row[$this->column($headings, 'age_at_admission')],
        );
        $this->assertSame(
            FollowUpChild::formatAgeAtAdmission('2024-11-19', '2026-08-26'),
            $row[$this->column($headings, 'age_at_last_visit')],
        );

        app()->setLocale('en');

        $this->assertSame('21 months', FollowUpChild::formatAgeAtAdmission('2024-11-19', '2026-08-19'));
        $this->assertSame('21 months and 7 days', FollowUpChild::formatAgeAtAdmission('2024-11-19', '2026-08-26'));
    }

    public function test_age_at_last_visit_is_blank_without_a_visit_or_without_a_date_of_birth(): void
    {
        $noVisits = $this->episode(['id_number' => '600000091', 'dob' => '2024-11-19']);

        $noDob = $this->episode(['id_number' => '600000092', 'dob' => null]);
        $noDob->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110]);

        [$headings, $rows] = $this->csv(FollowUpChild::query());

        $column = $this->column($headings, 'age_at_last_visit');

        $this->assertSame('', $this->row($headings, $rows, $noVisits)[$column]);
        $this->assertSame('', $this->row($headings, $rows, $noDob)[$column]);
        $this->assertSame('', $this->row($headings, $rows, $noDob)[$this->column($headings, 'age_at_admission')]);
    }

    // -----------------------------------------------------------------
    // Import contract: the new columns are export-only
    // -----------------------------------------------------------------

    public function test_the_importable_field_list_is_unchanged(): void
    {
        $definition = ImportDefinition::get('follow_up_children');

        $this->assertSame([
            'id_number', 'child_name', 'sex', 'dob', 'age',
            'mobile_number', 'shelter_name', 'governorate', 'causes_of_admission',
            'admitted_with', 'admission_type', 'admission_date', 'discharge_date',
            'discharge_outcome', 'notes',
        ], $definition->fields());

        foreach (FollowUpChildrenExport::COMPUTED_FIELDS as $field) {
            $this->assertContains($field, $definition->computed);
            $this->assertContains($field, $definition->exporter()->fields());
        }
    }

    public function test_the_export_only_columns_are_not_template_columns_and_are_ignored_on_upload(): void
    {
        $schema = new ImportSchema(ImportDefinition::get('follow_up_children'));

        foreach (FollowUpChildrenExport::COMPUTED_FIELDS as $field) {
            foreach (['en', 'ar'] as $locale) {
                $heading = trans('fields.' . $field, [], $locale);

                $this->assertNotContains($heading, $schema->headings(), "[{$field}] must not be a template column.");
                $this->assertNull($schema->resolveHeading($heading), "[{$field}] must not resolve to an import column.");
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Stream the export into memory and parse it back.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function csv(Builder $query): array
    {
        $handle = fopen('php://memory', 'w+');

        (new FollowUpChildrenExport($query))->writeCsv($handle);

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'The CSV must open as UTF-8 in Excel.');

        return $this->parse($content);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function parse(string $content): array
    {
        $content = substr($content, 3);

        $lines = array_values(array_filter(explode("\r\n", $content), fn (string $line): bool => $line !== ''));
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', '\\'), $lines);

        return [array_shift($rows), $rows];
    }

    private function column(array $headings, string $field): int
    {
        $index = array_search(__('fields.' . $field), $headings, true);

        $this->assertNotFalse($index, "No column for [{$field}].");

        return $index;
    }

    /** The exported row for one episode, found by its ID number and admission date. */
    private function row(array $headings, array $rows, FollowUpChild $episode): array
    {
        $id = $this->column($headings, 'id_number');
        $admitted = $this->column($headings, 'admission_date');

        foreach ($rows as $row) {
            if ($row[$id] === $episode->id_number && $row[$admitted] === $episode->admission_date?->format('Y-m-d')) {
                return $row;
            }
        }

        $this->fail("No exported row for episode [{$episode->getKey()}].");
    }

    private function episode(array $attributes): FollowUpChild
    {
        return FollowUpChild::create(array_merge([
            'id_number' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-08-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-06-01',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ], $attributes));
    }

    /** An episode admitted on 1 August, seen twice and closed on 20 August. */
    private function closedEpisode(string $programme, string $outcome, string $idNumber): FollowUpChild
    {
        $episode = $this->episode([
            'id_number' => $idNumber, 'admitted_with' => $programme,
            'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => $outcome,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-01', 'muac' => $programme === 'SAM' ? 110 : 118]);
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-08', 'muac' => $programme === 'SAM' ? 111 : 119]);

        return $episode->fresh();
    }

    private function screening(string $childId, int $muac): Child
    {
        $date = Carbon::today()->toDateString();

        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $childId,
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'sex' => 'male',
            'date_of_birth' => '2025-08-01',
            'muac_mm' => $muac,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
        ]);
    }
}
