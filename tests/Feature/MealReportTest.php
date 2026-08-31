<?php

namespace Tests\Feature;

use App\Exports\MealReport\MealReportExport;
use App\Filament\Pages\MealReport;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\SiteVocabulary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MealReportTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 7;

    private const SITE = 'Mossab Camp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function service(): MealReportService
    {
        return app(MealReportService::class);
    }

    /** @return array<string, int|float|string|null> */
    private function totals(string $sheet, ?string $site = self::SITE): array
    {
        return $this->service()->build(self::YEAR, self::MONTH, $site)[$sheet]['totals'];
    }

    // -----------------------------------------------------------------
    // Layout fidelity against the official template
    // -----------------------------------------------------------------

    public function test_the_exported_workbook_reproduces_the_template_headers_exactly(): void
    {
        $template = IOFactory::load(base_path('tests/Fixtures/meal-report-template.xlsx'));

        $path = $this->writeWorkbook([]);
        $produced = IOFactory::load($path);

        foreach (MealReportLayout::sheets() as $name) {
            $expected = $template->getSheetByName($name);
            $actual = $produced->getSheetByName($name);

            $this->assertNotNull($actual, "Sheet [{$name}] is missing from the export.");

            $leafRow = MealReportLayout::LEAF_ROW[$name];
            $width = count(MealReportLayout::columns($name));

            // Same merged ranges across the whole header block.
            $normalise = function ($sheet) use ($leafRow): array {
                $ranges = array_filter(
                    array_keys($sheet->getMergeCells()),
                    fn (string $range): bool => (int) filter_var(explode(':', $range)[0], FILTER_SANITIZE_NUMBER_INT) <= $leafRow,
                );
                sort($ranges);

                return array_values($ranges);
            };

            $this->assertSame(
                $normalise($expected),
                $normalise($actual),
                "Merged header ranges differ on sheet [{$name}].",
            );

            // Same caption in every header cell.
            for ($row = 2; $row <= $leafRow; $row++) {
                for ($column = 1; $column <= $width; $column++) {
                    $this->assertSame(
                        (string) $expected->getCellByColumnAndRow($column, $row)->getValue(),
                        (string) $actual->getCellByColumnAndRow($column, $row)->getValue(),
                        "Header caption differs at [{$name}] column {$column}, row {$row}.",
                    );
                }
            }
        }

        @unlink($path);
    }

    public function test_the_kit_distribution_sheet_is_not_produced(): void
    {
        $path = $this->writeWorkbook([]);
        $names = IOFactory::load($path)->getSheetNames();

        $this->assertSame(MealReportLayout::sheets(), $names);
        $this->assertNotContains('KIT distribution', $names);

        @unlink($path);
    }

    public function test_every_sheet_declares_one_column_key_per_template_column(): void
    {
        foreach (MealReportLayout::sheets() as $sheet) {
            $columns = MealReportLayout::columns($sheet);

            $this->assertSame(
                count(MealReportLayout::leafLabels($sheet)),
                count($columns),
                "Column keys and template columns are out of step on [{$sheet}].",
            );

            $this->assertSame($columns, array_values(array_unique($columns)), "Duplicate column key on [{$sheet}].");
        }
    }

    // -----------------------------------------------------------------
    // Screening aggregation
    // -----------------------------------------------------------------

    public function test_children_are_counted_by_age_band_status_sex_and_visit_type(): void
    {
        // 118mm -> MAM, 110mm -> SAM, 130mm -> Normal (Child::classifyMuac).
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['muac_mm' => 110, 'sex' => 'female', 'visit_type' => 'follow_up', 'age' => 30]);
        $this->child(['muac_mm' => 130, 'sex' => 'female', 'visit_type' => 'new', 'age' => 40]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(2, $totals['c6_23_new_mam_male']);
        $this->assertSame(1, $totals['c24_59_fu_sam_female']);
        $this->assertSame(1, $totals['c24_59_new_normal_female']);
        $this->assertSame(0, $totals['c6_23_new_sam_male']);
    }

    public function test_oedema_outranks_the_muac_reading(): void
    {
        $this->child(['muac_mm' => 130, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'has_oedema' => true]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(1, $totals['c6_23_new_oedema_male']);
        $this->assertSame(0, $totals['c6_23_new_normal_male'], 'An oedematous child must not also be counted as Normal.');
    }

    public function test_children_outside_6_to_59_months_are_excluded(): void
    {
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 3]);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 70]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(0, $totals['c6_23_new_mam_male']);
        $this->assertSame(0, $totals['c24_59_new_mam_male']);
    }

    public function test_the_muac_boundary_follows_the_shared_classifier(): void
    {
        // Child::classifyMuac: <=115 SAM, 116-124 MAM, >=125 Normal.
        $this->child(['muac_mm' => 115, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['muac_mm' => 116, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['muac_mm' => 125, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['muac_mm' => 126, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(1, $totals['c6_23_new_sam_male']);
        $this->assertSame(1, $totals['c6_23_new_mam_male']);
        $this->assertSame(2, $totals['c6_23_new_normal_male']);

        // The report must agree with the classifier itself, not a copy of it.
        $this->assertSame('SAM', Child::classifyMuac(115));
        $this->assertSame('Normal', Child::classifyMuac(125));
    }

    public function test_women_are_counted_by_status_wasting_and_age_bracket(): void
    {
        // PregnantLactatingWoman::classifyMuac: <230 Malnourished, else Normal.
        $this->woman(['muac_mm' => 240, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25]);
        $this->woman(['muac_mm' => 220, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 17]);
        $this->woman(['muac_mm' => 240, 'status_type' => 'lactating', 'visit_type' => 'follow_up', 'age' => 19]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame(1, $totals['pw_new_wasted_u18']);
        $this->assertSame(1, $totals['bf_fu_not_wasted_a18_19']);
    }

    public function test_the_23cm_wasting_boundary_matches_the_template(): void
    {
        $this->woman(['muac_mm' => 229.9, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25]);
        $this->woman(['muac_mm' => 230, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25]);

        $totals = $this->totals(MealReportLayout::SHEET_SCREENING);

        $this->assertSame(1, $totals['pw_new_wasted_a20p']);
        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
    }

    // -----------------------------------------------------------------
    // IYCF and CMAM aggregation
    // -----------------------------------------------------------------

    public function test_individual_counselling_is_counted_by_help_type_and_visit(): void
    {
        $this->counseling(['consultation' => 'bf_support', 'mother_visit_type' => 'new']);
        $this->counseling(['consultation' => 'bf_support', 'mother_visit_type' => 'new']);
        $this->counseling(['consultation' => 'relactation', 'mother_visit_type' => 'follow_up']);

        $totals = $this->totals(MealReportLayout::SHEET_IYCF);

        $this->assertSame(2, $totals['help_bf_new']);
        $this->assertSame(1, $totals['help_relactation_fu']);
        $this->assertSame(0, $totals['help_cf_new']);
    }

    public function test_group_sessions_count_distinct_groups_and_their_participants(): void
    {
        $this->groupSession(['session_group_number' => 'G1', 'category' => 'pregnant', 'visit_type' => 'new']);
        $this->groupSession(['session_group_number' => 'G1', 'category' => 'pregnant', 'visit_type' => 'new']);
        $this->groupSession(['session_group_number' => 'G2', 'category' => 'caregiver_child_6_23_months', 'visit_type' => 'follow_up']);

        $totals = $this->totals(MealReportLayout::SHEET_IYCF);

        $this->assertSame(2, $totals['group_sessions'], 'Two distinct session groups ran that day.');
        $this->assertSame(3, $totals['participants_total']);
        $this->assertSame(2, $totals['part_pregnant_new']);
        $this->assertSame(1, $totals['part_cg_child_fu']);
    }

    public function test_cmam_admissions_and_discharges_are_counted_with_length_of_stay(): void
    {
        $this->followUpChild([
            'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12,
            'admission_date' => '2026-07-01', 'discharge_date' => '2026-07-11', 'discharge_outcome' => 'cured',
        ]);
        $this->followUpChild([
            'admitted_with' => 'MAM', 'sex' => 'F', 'age' => 30,
            'admission_date' => '2026-07-02', 'discharge_date' => null, 'discharge_outcome' => null,
        ]);

        $totals = $this->totals(MealReportLayout::SHEET_CMAM);

        $this->assertSame(1, $totals['sam_adm_6_23_new_male']);
        $this->assertSame(1, $totals['mam_adm_24_59_new_female']);
        $this->assertSame(1, $totals['sam_dis_recovered_6_23_male']);
        $this->assertSame(10.0, $totals['sam_los_6_23_male'], 'Length of stay is 1 July to 11 July.');
    }

    public function test_discharge_outcomes_map_onto_the_template_categories(): void
    {
        foreach (['cured' => 'recovered', 'defaulted' => 'defaulted', 'died' => 'died', 'discharge_to_opt' => 'referred_medical', 'discharge_to_other' => 'other'] as $stored => $expected) {
            $this->followUpChild([
                'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12,
                'admission_date' => '2026-07-01', 'discharge_date' => '2026-07-05', 'discharge_outcome' => $stored,
            ]);
        }

        $totals = $this->totals(MealReportLayout::SHEET_CMAM);

        foreach (['recovered', 'defaulted', 'died', 'referred_medical', 'other'] as $outcome) {
            $this->assertSame(1, $totals["sam_dis_{$outcome}_6_23_male"], "Outcome [{$outcome}] was not counted.");
        }

        $this->assertSame(1, $totals['sam_referred_6_23_male']);
    }

    // -----------------------------------------------------------------
    // Site filtering
    // -----------------------------------------------------------------

    public function test_the_site_filter_translates_across_each_module_vocabulary(): void
    {
        // Same camp, three different spellings across three modules.
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'type_of_site' => 'Mossab Camp']);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'type_of_site' => 'El Qoqa']);
        $this->counseling(['consultation' => 'bf_support', 'mother_visit_type' => 'new', 'shelter_name' => 'mosaab_camp']);
        $this->counseling(['consultation' => 'bf_support', 'mother_visit_type' => 'new', 'shelter_name' => 'el_qoqa']);
        $this->followUpChild([
            'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-07-01',
            'shelter_name' => 'Mosaab camp shelter',
        ]);
        $this->followUpChild([
            'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-07-01',
            'shelter_name' => 'El Qoqa',
        ]);

        $this->assertSame(1, $this->totals(MealReportLayout::SHEET_SCREENING)['c6_23_new_mam_male']);
        $this->assertSame(1, $this->totals(MealReportLayout::SHEET_IYCF)['help_bf_new']);
        $this->assertSame(1, $this->totals(MealReportLayout::SHEET_CMAM)['sam_adm_6_23_new_male']);

        // "All sites" lifts the filter.
        $this->assertSame(2, $this->totals(MealReportLayout::SHEET_SCREENING, SiteVocabulary::ALL)['c6_23_new_mam_male']);
        $this->assertSame(2, $this->totals(MealReportLayout::SHEET_IYCF, SiteVocabulary::ALL)['help_bf_new']);
        $this->assertSame(2, $this->totals(MealReportLayout::SHEET_CMAM, SiteVocabulary::ALL)['sam_adm_6_23_new_male']);
    }

    public function test_records_outside_the_selected_month_are_excluded(): void
    {
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'date' => '2026-06-15']);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'date' => '2026-07-15']);

        $this->assertSame(1, $this->totals(MealReportLayout::SHEET_SCREENING)['c6_23_new_mam_male']);
    }

    public function test_days_are_reported_separately_and_summed_in_the_total_row(): void
    {
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'date' => '2026-07-03']);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'date' => '2026-07-09']);
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'date' => '2026-07-09']);

        $sheet = $this->service()->build(self::YEAR, self::MONTH, self::SITE)[MealReportLayout::SHEET_SCREENING];

        $this->assertCount(2, $sheet['rows']);
        $this->assertSame([3, 9], array_column($sheet['rows'], 'day'));
        $this->assertSame([1, 2], array_column($sheet['rows'], 'c6_23_new_mam_male'));
        $this->assertSame(3, $sheet['totals']['c6_23_new_mam_male']);
        $this->assertSame(self::SITE, $sheet['rows'][0]['mba']);
        $this->assertSame('July', $sheet['rows'][0]['month']);
    }

    // -----------------------------------------------------------------
    // Columns with no data source
    // -----------------------------------------------------------------

    public function test_columns_with_no_data_source_are_left_blank_not_zero(): void
    {
        $this->child(['muac_mm' => 118, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $unsupported = MealReportService::unsupportedColumns();
        $data = $this->service()->build(self::YEAR, self::MONTH, self::SITE);

        $this->assertNotEmpty($unsupported[MealReportLayout::SHEET_CMAM]);
        $this->assertContains('bms_violations', $unsupported[MealReportLayout::SHEET_IYCF]);

        foreach ($unsupported as $sheet => $keys) {
            foreach ($keys as $key) {
                $this->assertNull($data[$sheet]['totals'][$key], "[{$sheet}.{$key}] should be blank, not zero.");
            }
        }

        // A measured column that genuinely saw nothing still reports 0.
        $this->assertSame(0, $data[MealReportLayout::SHEET_SCREENING]['totals']['c6_23_new_sam_male']);
    }

    public function test_unsupported_keys_all_exist_in_the_layout(): void
    {
        foreach (MealReportService::unsupportedColumns() as $sheet => $keys) {
            $columns = MealReportLayout::columns($sheet);

            foreach ($keys as $key) {
                $this->assertContains($key, $columns, "[{$key}] is not a column of [{$sheet}].");
            }
        }
    }

    // -----------------------------------------------------------------
    // Page, permissions and the required site filter
    // -----------------------------------------------------------------

    public function test_only_authorised_roles_can_reach_the_page(): void
    {
        $this->actingAsRole('Admin');
        $this->assertTrue(MealReport::canAccess());

        $this->actingAsRole('M&E');
        $this->assertTrue(MealReport::canAccess());

        $this->actingAsRole('Data Entry');
        $this->assertFalse(MealReport::canAccess());

        $this->actingAsRole('Viewer');
        $this->assertFalse(MealReport::canAccess());
    }

    public function test_the_page_renders_with_the_site_field_empty(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(MealReport::class)
            ->assertSuccessful()
            ->assertFormSet(['year' => now()->year, 'month' => now()->month, 'site' => null])
            ->assertSee(__('fields.meal_site_required'));
    }

    public function test_the_export_action_is_disabled_until_a_site_is_chosen(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(MealReport::class)
            ->assertActionDisabled('exportMealReport')
            ->fillForm(['site' => self::SITE])
            ->assertActionEnabled('exportMealReport');
    }

    public function test_exporting_without_a_site_is_refused_with_a_message(): void
    {
        $this->actingAsRole('Admin');

        $component = Livewire::test(MealReport::class);
        $this->assertNull($component->instance()->export());
        $component->assertNotified(__('fields.meal_site_required'));
    }

    public function test_choosing_a_site_produces_the_preview(): void
    {
        $this->child(['muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->actingAsRole('Admin');

        Livewire::test(MealReport::class)
            ->fillForm(['year' => self::YEAR, 'month' => self::MONTH, 'site' => self::SITE])
            ->assertSee(__('fields.meal_preview'))
            ->assertSee(self::SITE)
            ->assertSee(__('fields.meal_sam_cases'));
    }

    public function test_a_user_without_export_permission_cannot_export(): void
    {
        $this->actingAsRole('Data Entry');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new MealReport)->export();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function writeWorkbook(array $data): string
    {
        foreach (MealReportLayout::sheets() as $sheet) {
            $data[$sheet] ??= ['rows' => [], 'totals' => []];
        }

        $path = tempnam(sys_get_temp_dir(), 'meal') . '.xlsx';
        file_put_contents($path, Excel::raw(new MealReportExport($data), \Maatwebsite\Excel\Excel::XLSX));

        return $path;
    }

    private function child(array $attributes): Child
    {
        $date = $attributes['date'] ?? '2026-07-10';

        return Child::create([
            'visit_type' => $attributes['visit_type'],
            'name' => 'Test child',
            'child_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'sex' => $attributes['sex'],
            'date_of_birth' => \Carbon\Carbon::parse($date)->subMonths($attributes['age'])->toDateString(),
            'muac_mm' => $attributes['muac_mm'],
            'has_oedema' => $attributes['has_oedema'] ?? false,
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'governorate' => 'gaza',
            'type_of_site' => $attributes['type_of_site'] ?? self::SITE,
        ]);
    }

    private function woman(array $attributes): PregnantLactatingWoman
    {
        $date = $attributes['date'] ?? '2026-07-10';

        return PregnantLactatingWoman::create([
            'visit_type' => $attributes['visit_type'],
            'full_name_ar' => 'اختبار',
            'mother_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'date_of_birth' => \Carbon\Carbon::parse($date)->subYears($attributes['age'])->toDateString(),
            'status_type' => $attributes['status_type'],
            'muac_mm' => $attributes['muac_mm'],
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'governorate' => 'gaza',
            'type_of_site' => $attributes['type_of_site'] ?? self::SITE,
        ]);
    }

    private function counseling(array $attributes): IndividualCounseling
    {
        $date = $attributes['date'] ?? '2026-07-10';

        return IndividualCounseling::create([
            'date' => $date,
            'child_name' => 'Test child',
            'child_visit_type' => $attributes['child_visit_type'] ?? 'new',
            'child_dob' => \Carbon\Carbon::parse($date)->subMonths($attributes['child_age'] ?? 12)->toDateString(),
            'gender' => $attributes['gender'] ?? 'M',
            'mother_id_number' => '123456789',
            'mother_name' => 'Test mother',
            'mother_visit_type' => $attributes['mother_visit_type'],
            'mother_dob' => \Carbon\Carbon::parse($date)->subYears($attributes['mother_age'] ?? 25)->toDateString(),
            'consultation' => $attributes['consultation'],
            'status' => $attributes['status'] ?? null,
            'outcome' => $attributes['outcome'] ?? null,
            'p_l' => $attributes['p_l'] ?? 'L',
            'shelter_name' => $attributes['shelter_name'] ?? 'mosaab_camp',
        ]);
    }

    private function groupSession(array $attributes): GroupSession
    {
        return GroupSession::create([
            'session_date' => $attributes['date'] ?? '2026-07-10',
            'session_group_number' => $attributes['session_group_number'],
            'session_subject' => 'bf_support',
            'locality' => 'tal_al_hawa',
            'shelter_name' => $attributes['shelter_name'] ?? 'mosaab_camp',
            'id_number' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'full_name_ar' => 'اختبار',
            'visit_type' => $attributes['visit_type'],
            'category' => $attributes['category'],
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'marital_status' => 'married',
            'phone_number' => '0599123456',
        ]);
    }

    private function followUpChild(array $attributes): FollowUpChild
    {
        return FollowUpChild::create([
            'id_number' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'child_name' => 'Test child',
            'sex' => $attributes['sex'],
            'dob' => \Carbon\Carbon::parse($attributes['admission_date'])->subMonths($attributes['age'])->toDateString(),
            'mobile_number' => '0599123456',
            'shelter_name' => $attributes['shelter_name'] ?? 'Mosaab camp',
            'governorate' => 'Gaza',
            'admitted_with' => $attributes['admitted_with'],
            'admission_date' => $attributes['admission_date'],
            'discharge_date' => $attributes['discharge_date'] ?? null,
            'discharge_outcome' => $attributes['discharge_outcome'] ?? null,
        ]);
    }
}
