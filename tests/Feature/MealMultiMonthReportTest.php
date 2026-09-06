<?php

namespace Tests\Feature;

use App\Exports\MealReport\MealReportExport;
use App\Filament\Pages\MealReport;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use App\Support\MealReport\SiteVocabulary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * One workbook, several months.
 *
 * The report is asked for a run of months and has to lay them out inside the
 * template's existing sheets - in calendar order, each month counted strictly
 * on its own dates, and no month dropped for being empty.
 */
class MealMultiMonthReportTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const AUGUST = 8;

    private const SEPTEMBER = 9;

    private const OCTOBER = 10;

    private const SITE = 'Mossab Camp';

    private const OTHER_SITE = 'El Salam Camp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // The period itself
    // -----------------------------------------------------------------

    public function test_the_period_expands_to_the_months_between_its_ends(): void
    {
        $period = ReportPeriod::make(self::YEAR, self::AUGUST, self::OCTOBER);

        $this->assertSame([8, 9, 10], $period->months());
        $this->assertSame(3, $period->monthCount());
        $this->assertFalse($period->isSingleMonth());

        // The upper bound is the end of the last day, so a record captured on
        // 31 October is inside the window rather than a second past it.
        $this->assertSame(['2026-08-01 00:00:00', '2026-10-31 23:59:59'], $period->dateRange());
    }

    public function test_a_single_month_is_a_period_whose_ends_are_equal(): void
    {
        $period = ReportPeriod::month(self::YEAR, self::AUGUST);

        $this->assertSame([8], $period->months());
        $this->assertTrue($period->isSingleMonth());
        $this->assertSame(['2026-08-01 00:00:00', '2026-08-31 23:59:59'], $period->dateRange());
    }

    public function test_a_period_given_the_wrong_way_round_still_reports(): void
    {
        $period = ReportPeriod::make(self::YEAR, self::OCTOBER, self::AUGUST);

        $this->assertSame([8, 9, 10], $period->months());
    }

    public function test_the_period_crosses_a_year_end_by_its_own_dates(): void
    {
        $period = ReportPeriod::make(2027, 1, 2);

        $this->assertSame(['2027-01-01 00:00:00', '2027-02-28 23:59:59'], $period->dateRange());
        $this->assertSame('January', $period->monthLabel(1));
    }

    // -----------------------------------------------------------------
    // Months inside one file
    // -----------------------------------------------------------------

    public function test_three_months_are_written_into_one_report_in_calendar_order(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-15', 'muac_mm' => 118, 'sex' => 'female', 'visit_type' => 'follow_up', 'age' => 30]);
        $this->child(['date' => '2026-10-20', 'muac_mm' => 130, 'sex' => 'male', 'visit_type' => 'new', 'age' => 40]);

        $sheet = $this->screening(self::AUGUST, self::OCTOBER);

        // August, September, October - and not the alphabetical August,
        // October, September.
        $this->assertSame(
            ['August', 'September', 'October'],
            array_values(array_unique(array_column($sheet['rows'], 'month'))),
        );

        $this->assertSame([10, 15, 20], array_column($sheet['rows'], 'day'));
    }

    public function test_each_month_holds_only_its_own_records(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-15', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-10-20', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $rows = $this->screening(self::AUGUST, self::OCTOBER)['rows'];

        foreach ($rows as $row) {
            $this->assertSame(
                1,
                $row['c6_23_new_sam_male'],
                "The {$row['month']} row must hold its own single record and no other month's.",
            );
        }

        // Nothing leaked sideways: three separate ones, never a three.
        $this->assertSame(3, count($rows));
    }

    public function test_the_total_row_sums_only_the_selected_months(): void
    {
        // Inside the window.
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-15', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        // Outside it, on either side.
        $this->child(['date' => '2026-07-31', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-10-01', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $totals = $this->screening(self::AUGUST, self::SEPTEMBER)['totals'];

        $this->assertSame(2, $totals['c6_23_new_sam_male'], 'The total is August + September, not the whole database.');
        $this->assertSame('Total', $totals['mba']);
    }

    public function test_a_month_with_no_data_keeps_its_place_between_two_that_have_some(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        // September is deliberately left empty.
        $this->child(['date' => '2026-10-20', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $sheet = $this->screening(self::AUGUST, self::OCTOBER);
        $months = array_column($sheet['rows'], 'month');

        $this->assertSame(['August', 'September', 'October'], $months);

        $september = $sheet['rows'][1];

        $this->assertSame('', $september['day'], 'An empty month has no day to report.');
        $this->assertSame(0, $september['c6_23_new_sam_male'], 'A supported column reads zero, not blank.');
    }

    public function test_every_month_of_an_entirely_empty_period_is_still_reported(): void
    {
        $sheet = $this->screening(self::AUGUST, self::OCTOBER);

        $this->assertSame(['August', 'September', 'October'], array_column($sheet['rows'], 'month'));
    }

    public function test_the_month_starts_mark_the_first_row_of_each_month(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-08-11', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-15', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $sheet = $this->screening(self::AUGUST, self::SEPTEMBER);

        $this->assertSame([0, 2], $sheet['monthStarts']);
    }

    // -----------------------------------------------------------------
    // Date boundaries
    // -----------------------------------------------------------------

    public function test_the_first_and_last_day_of_every_month_fall_in_that_month(): void
    {
        foreach (['2026-08-01', '2026-08-31', '2026-09-01', '2026-09-30', '2026-10-01', '2026-10-31'] as $date) {
            $this->child(['date' => $date, 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        }

        $rows = $this->screening(self::AUGUST, self::OCTOBER)['rows'];

        $placed = [];

        foreach ($rows as $row) {
            $placed[] = $row['month'] . ' ' . $row['day'];
        }

        $this->assertSame(
            ['August 1', 'August 31', 'September 1', 'September 30', 'October 1', 'October 31'],
            $placed,
        );
    }

    public function test_the_day_before_and_after_the_window_are_both_excluded(): void
    {
        $this->child(['date' => '2026-07-31', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-10-01', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $sheet = $this->screening(self::AUGUST, self::SEPTEMBER);

        $this->assertSame(0, $sheet['totals']['c6_23_new_sam_male']);
        // Both months still present, both empty.
        $this->assertSame(['August', 'September'], array_column($sheet['rows'], 'month'));
    }

    // -----------------------------------------------------------------
    // Site filtering across the window
    // -----------------------------------------------------------------

    public function test_a_chosen_site_filters_every_month_of_the_window(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'type_of_site' => self::OTHER_SITE]);

        $mine = $this->screening(self::AUGUST, self::SEPTEMBER, self::SITE);
        $all = $this->screening(self::AUGUST, self::SEPTEMBER, SiteVocabulary::ALL);

        $this->assertSame(1, $mine['totals']['c6_23_new_sam_male']);
        $this->assertSame(2, $all['totals']['c6_23_new_sam_male']);

        // The other camp's September record is filtered out of September too,
        // not only out of the total.
        $september = collect($mine['rows'])->firstWhere('month', 'September');
        $this->assertSame(0, $september['c6_23_new_sam_male']);
        $this->assertSame(self::SITE, $mine['rows'][0]['mba']);
    }

    // -----------------------------------------------------------------
    // Screening: status, visit type and missing measurements
    // -----------------------------------------------------------------

    public function test_screening_visit_type_is_new_or_follow_up_across_months(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-09-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'follow_up', 'age' => 12]);

        $totals = $this->screening(self::AUGUST, self::SEPTEMBER)['totals'];

        $this->assertSame(1, $totals['c6_23_new_sam_male']);
        $this->assertSame(1, $totals['c6_23_fu_sam_male']);
    }

    public function test_a_screening_with_no_muac_is_never_reported_as_normal(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => null, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $sheet = $this->screening(self::AUGUST, self::AUGUST);

        foreach (['normal', 'mam', 'sam', 'oedema'] as $status) {
            $this->assertSame(
                0,
                $sheet['totals']["c6_23_new_{$status}_male"],
                "An unmeasured child must not be counted under [{$status}].",
            );
        }

        $this->assertSame(1, $sheet['review']['children_missing_muac'], 'It is reported for review instead.');
    }

    public function test_oedema_still_classifies_a_child_with_no_muac(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => null, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12, 'has_oedema' => true]);

        $sheet = $this->screening(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $sheet['totals']['c6_23_new_oedema_male']);
        $this->assertSame(0, $sheet['review']['children_missing_muac']);
    }

    public function test_a_woman_with_no_muac_is_reported_for_review_not_as_not_wasted(): void
    {
        $this->woman(['date' => '2026-08-10', 'muac_mm' => null, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25]);

        $sheet = $this->screening(self::AUGUST, self::AUGUST);

        $this->assertSame(0, $sheet['totals']['pw_new_not_wasted_a20p']);
        $this->assertSame(0, $sheet['totals']['pw_new_wasted_a20p']);
        $this->assertSame(1, $sheet['review']['women_missing_muac']);
    }

    public function test_pbw_screening_counts_come_from_the_women_module(): void
    {
        $this->woman(['date' => '2026-08-10', 'muac_mm' => 240, 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25]);
        $this->woman(['date' => '2026-09-10', 'muac_mm' => 210, 'status_type' => 'lactating', 'visit_type' => 'follow_up', 'age' => 17]);

        $totals = $this->screening(self::AUGUST, self::SEPTEMBER)['totals'];

        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame(1, $totals['bf_fu_wasted_u18']);
    }

    // -----------------------------------------------------------------
    // CMAM is the treatment journey, not the screening record
    // -----------------------------------------------------------------

    public function test_repeated_screening_creates_no_cmam_admission(): void
    {
        // The same child, screened three times across three months. No
        // follow_up_children row exists, so CMAM stays empty.
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
            $this->child(['date' => $date, 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'follow_up', 'age' => 12, 'child_id' => '111111111']);
        }

        $cmam = $this->sheet(MealReportLayout::SHEET_CMAM, self::AUGUST, self::OCTOBER);

        foreach ($cmam['totals'] as $key => $value) {
            if (str_contains($key, '_adm_') && is_numeric($value)) {
                $this->assertSame(0, $value, "Screening must not create the CMAM admission [{$key}].");
            }
        }
    }

    public function test_a_normal_screening_is_not_a_cmam_recovery(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 140, 'sex' => 'male', 'visit_type' => 'follow_up', 'age' => 12]);

        $totals = $this->sheet(MealReportLayout::SHEET_CMAM, self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(0, $totals['mam_dis_recovered_6_23_male']);
        $this->assertSame(0, $totals['sam_dis_recovered_6_23_male']);
    }

    public function test_an_admission_and_its_discharge_land_in_their_own_months(): void
    {
        // Admitted in August, discharged in October: each event is counted on
        // its own date, in its own month.
        $this->followUpChild([
            'sex' => 'M',
            'age' => 12,
            'admitted_with' => 'SAM',
            'admission_date' => '2026-08-05',
            'discharge_date' => '2026-10-12',
            'discharge_outcome' => 'cured',
        ]);

        $rows = collect($this->sheet(MealReportLayout::SHEET_CMAM, self::AUGUST, self::OCTOBER)['rows']);

        $august = $rows->firstWhere('month', 'August');
        $october = $rows->firstWhere('month', 'October');

        $this->assertSame(1, $august['sam_adm_6_23_new_male']);
        $this->assertSame(0, $august['sam_dis_recovered_6_23_male']);

        $this->assertSame(0, $october['sam_adm_6_23_new_male']);
        $this->assertSame(1, $october['sam_dis_recovered_6_23_male']);
    }

    public function test_an_open_case_is_not_counted_as_a_discharge(): void
    {
        $child = $this->followUpChild([
            'sex' => 'M',
            'age' => 12,
            'admitted_with' => 'MAM',
            'admission_date' => '2026-08-05',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        // Recorded CMAM visits are the treatment journey; they are not
        // discharges and do not close the case.
        foreach ([1, 2, 3] as $number) {
            FollowUpChildVisit::create([
                'follow_up_child_id' => $child->id,
                'visit_number' => $number,
                'visit_date' => '2026-08-' . str_pad((string) (5 + $number), 2, '0', STR_PAD_LEFT),
                'muac' => 118,
            ]);
        }

        $totals = $this->sheet(MealReportLayout::SHEET_CMAM, self::AUGUST, self::OCTOBER)['totals'];

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(3, $child->visits()->count(), 'The visits are recorded against the case.');

        foreach ($totals as $key => $value) {
            if (str_contains($key, '_dis_') && is_numeric($value)) {
                $this->assertSame(0, $value, "An open case must not be discharged as [{$key}].");
            }
        }
    }

    // -----------------------------------------------------------------
    // IYCF and its own dates
    // -----------------------------------------------------------------

    public function test_iycf_figures_come_from_the_iycf_modules_on_their_own_dates(): void
    {
        // A screening record in the same window must not reach this sheet.
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $this->counseling(['date' => '2026-08-12', 'mother_visit_type' => 'new', 'consultation' => 'bf_support']);
        $this->counseling(['date' => '2026-09-12', 'mother_visit_type' => 'follow_up', 'consultation' => 'relactation']);
        $this->groupSession(['date' => '2026-09-14', 'session_group_number' => 'G1', 'visit_type' => 'new', 'category' => 'pregnant']);

        $rows = collect($this->sheet(MealReportLayout::SHEET_IYCF, self::AUGUST, self::SEPTEMBER)['rows']);

        $august = $rows->firstWhere('day', 12);
        $this->assertSame('August', $august['month']);
        $this->assertSame(1, $august['help_bf_new']);

        $september = $rows->where('month', 'September')->firstWhere('day', 12);
        $this->assertSame(1, $september['help_relactation_fu']);

        $sessions = $rows->where('month', 'September')->firstWhere('day', 14);
        $this->assertSame(1, $sessions['group_sessions']);
        $this->assertSame(1, $sessions['part_pregnant_new']);

        // The August screening produced no IYCF row of its own.
        $this->assertNull($rows->where('month', 'August')->firstWhere('day', 10));
    }

    // -----------------------------------------------------------------
    // The workbook
    // -----------------------------------------------------------------

    public function test_one_workbook_holds_every_month_in_the_template_sheets(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-10-20', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $data = $this->build(self::AUGUST, self::OCTOBER);

        $path = tempnam(sys_get_temp_dir(), 'meal') . '.xlsx';
        file_put_contents($path, Excel::raw(new MealReportExport($data), \Maatwebsite\Excel\Excel::XLSX));

        $book = IOFactory::load($path);

        // Still the template's sheets, and no sheet per month.
        $this->assertSame(MealReportLayout::sheets(), $book->getSheetNames());

        $sheet = $book->getSheetByName(MealReportLayout::SHEET_SCREENING);
        $first = MealReportLayout::FIRST_DATA_ROW[MealReportLayout::SHEET_SCREENING];

        // MONTH column, three months running down the same sheet, then Total.
        $months = [];
        for ($row = $first; $row <= $first + 2; $row++) {
            $months[] = (string) $sheet->getCell([2, $row])->getValue();
        }

        $this->assertSame(['August', 'September', 'October'], $months);
        $this->assertSame('Total', (string) $sheet->getCell([1, $first + 3])->getValue());

        @unlink($path);
    }

    public function test_the_header_block_is_unchanged_by_a_multi_month_report(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $template = IOFactory::load(base_path('tests/Fixtures/meal-report-template.xlsx'));

        $path = tempnam(sys_get_temp_dir(), 'meal') . '.xlsx';
        file_put_contents(
            $path,
            Excel::raw(new MealReportExport($this->build(self::AUGUST, self::OCTOBER)), \Maatwebsite\Excel\Excel::XLSX),
        );
        $produced = IOFactory::load($path);

        foreach (MealReportLayout::sheets() as $name) {
            $leafRow = MealReportLayout::LEAF_ROW[$name];
            $width = count(MealReportLayout::columns($name));

            for ($row = 2; $row <= $leafRow; $row++) {
                for ($column = 1; $column <= $width; $column++) {
                    $this->assertSame(
                        (string) $template->getSheetByName($name)->getCell([$column, $row])->getValue(),
                        (string) $produced->getSheetByName($name)->getCell([$column, $row])->getValue(),
                        "Header caption differs at [{$name}] column {$column}, row {$row}.",
                    );
                }
            }
        }

        @unlink($path);
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    public function test_the_page_exports_the_chosen_window_as_one_file(): void
    {
        $this->actingAsRole('Admin');
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        Excel::fake();

        Livewire::test(MealReport::class)
            ->fillForm([
                'year' => self::YEAR,
                'from_month' => self::AUGUST,
                'to_month' => self::OCTOBER,
                'site' => SiteVocabulary::ALL,
            ])
            ->callAction('exportMealReport');

        Excel::assertDownloaded('MEAL Monitoring Report - Aug-Oct-2026 - All Sites.xlsx');
    }

    public function test_a_single_month_export_is_still_named_for_that_month(): void
    {
        $this->actingAsRole('Admin');

        Excel::fake();

        Livewire::test(MealReport::class)
            ->fillForm([
                'year' => self::YEAR,
                'from_month' => self::AUGUST,
                'to_month' => self::AUGUST,
                'site' => self::SITE,
            ])
            ->callAction('exportMealReport');

        Excel::assertDownloaded('MEAL Monitoring Report - Aug-2026 - Mossab Camp.xlsx');
    }

    public function test_moving_the_start_past_the_end_drags_the_end_along(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(MealReport::class)
            ->fillForm(['from_month' => self::AUGUST, 'to_month' => self::AUGUST, 'site' => self::SITE])
            ->set('data.from_month', self::OCTOBER)
            ->assertFormSet(['from_month' => self::OCTOBER, 'to_month' => self::OCTOBER]);
    }

    public function test_the_preview_reports_the_window_it_will_export(): void
    {
        $this->actingAsRole('Admin');
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        Livewire::test(MealReport::class)
            ->fillForm([
                'year' => self::YEAR,
                'from_month' => self::AUGUST,
                'to_month' => self::OCTOBER,
                'site' => self::SITE,
            ])
            ->assertSee(ReportPeriod::make(self::YEAR, self::AUGUST, self::OCTOBER)->label())
            ->assertSee(__('fields.meal_sam_cases'));
    }

    // -----------------------------------------------------------------
    // Cost
    // -----------------------------------------------------------------

    /**
     * Reporting five months must not cost five times reporting one. Each
     * source is read once over the whole window and the rows are bucketed to
     * their month afterwards.
     */
    public function test_a_longer_window_does_not_cost_more_queries(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);
        $this->child(['date' => '2026-12-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $count = function (int $from, int $to): int {
            $statements = 0;

            DB::listen(function (QueryExecuted $query) use (&$statements): void {
                $statements++;
            });

            app(MealReportService::class)->buildPeriod(
                ReportPeriod::make(self::YEAR, $from, $to),
                self::SITE,
            );

            return $statements;
        };

        $one = $count(self::AUGUST, self::AUGUST);
        $five = $count(self::AUGUST, 12);

        $this->assertSame($one, $five, 'The window is read in one pass per source, whatever its length.');
    }

    public function test_the_children_are_read_once_over_the_whole_window(): void
    {
        $this->child(['date' => '2026-08-10', 'muac_mm' => 110, 'sex' => 'male', 'visit_type' => 'new', 'age' => 12]);

        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(MealReportService::class)->buildPeriod(
            ReportPeriod::make(self::YEAR, self::AUGUST, self::OCTOBER),
            self::SITE,
        );

        $children = array_values(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "children"') || str_contains($sql, 'from `children`'),
        ));

        $this->assertCount(1, $children);

        // A BETWEEN on the column itself, so the date index can be used - not
        // a year/month function wrapped around it.
        $this->assertStringContainsString('between', strtolower($children[0]));
        $this->assertStringNotContainsString('strftime', strtolower($children[0]));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, array> */
    private function build(int $from, int $to, ?string $site = self::SITE): array
    {
        return app(MealReportService::class)->buildPeriod(ReportPeriod::make(self::YEAR, $from, $to), $site);
    }

    /** @return array{rows: array, totals: array, monthStarts: array, review: array} */
    private function sheet(string $sheet, int $from, int $to, ?string $site = self::SITE): array
    {
        return $this->build($from, $to, $site)[$sheet];
    }

    /** @return array{rows: array, totals: array, monthStarts: array, review: array} */
    private function screening(int $from, int $to, ?string $site = self::SITE): array
    {
        return $this->sheet(MealReportLayout::SHEET_SCREENING, $from, $to, $site);
    }

    private function child(array $attributes): Child
    {
        $date = $attributes['date'];

        return Child::create([
            'visit_type' => $attributes['visit_type'],
            'name' => 'Test child',
            'child_id' => $attributes['child_id'] ?? (string) fake()->unique()->numberBetween(100000000, 999999999),
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
        $date = $attributes['date'];

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
        $date = $attributes['date'];

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
            'session_date' => $attributes['date'],
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
