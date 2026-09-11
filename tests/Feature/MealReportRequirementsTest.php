<?php

namespace Tests\Feature;

use App\Exports\MealReport\MealReportExport;
use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use App\Support\MealReport\SiteVocabulary;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The Screening sheet, requirement by requirement.
 *
 * One test per indicator the MEAL report has to get right - children by visit
 * type, age at the visit, sex, MUAC status and oedema; pregnant and
 * breastfeeding women by visit type, age group and the 23 cm threshold; the
 * two PWD blocks; the site, the month, the monthly totals and the workbook
 * that holds every month.
 */
class MealReportRequirementsTest extends TestCase
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
    // 1. Children: New vs Follow-up
    // -----------------------------------------------------------------

    public function test_children_new_and_follow_up_are_counted_from_the_stored_visit_type(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->child(['date' => '2026-08-11', 'visit_type' => 'follow_up', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(2, $totals['c6_23_new_mam_male']);
        $this->assertSame(1, $totals['c6_23_fu_mam_male']);
    }

    // -----------------------------------------------------------------
    // 2. Children: age in months at the visit
    // -----------------------------------------------------------------

    public function test_child_age_is_calculated_from_dob_at_the_visit_date_not_the_stored_age(): void
    {
        // DOB 15/10/2025, visit 15/08/2026: 10 months old at the visit. The
        // stored age says 30 months and must be ignored while a DOB exists.
        Child::create($this->childAttributes([
            'date_of_reporting' => '2026-08-15',
            'date_of_birth' => '2025-10-15',
            'age_months' => 30,
            'visit_type' => 'new',
            'muac_mm' => 118,
            'sex' => 'male',
        ]));

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['c6_23_new_mam_male'], 'Ten months old at the visit: the 6-23 band.');
        $this->assertSame(0, $totals['c24_59_new_mam_male'], 'The stored 30 months must not place the child in 24-59.');
    }

    public function test_the_stored_age_is_used_only_when_there_is_no_dob(): void
    {
        Child::create($this->childAttributes([
            'date_of_reporting' => '2026-08-15',
            'date_of_birth' => null,
            'age_months' => 30,
            'visit_type' => 'new',
            'muac_mm' => 118,
            'sex' => 'male',
        ]));

        $this->assertSame(1, $this->screening(self::AUGUST, self::AUGUST)['totals']['c24_59_new_mam_male']);
    }

    // -----------------------------------------------------------------
    // 3. Children: Male / Female
    // -----------------------------------------------------------------

    public function test_children_are_split_by_the_recorded_sex(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'female']);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'female']);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['c6_23_new_mam_male']);
        $this->assertSame(2, $totals['c6_23_new_mam_female']);
    }

    // -----------------------------------------------------------------
    // 4. Children: SAM / MAM / Normal
    // -----------------------------------------------------------------

    public function test_children_follow_the_shared_muac_classification(): void
    {
        foreach ([110 => 'SAM', 118 => 'MAM', 130 => 'Normal'] as $muac => $expected) {
            $this->assertSame($expected, Child::classifyMuac($muac), 'The report relies on the model classifier.');
            $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => $muac, 'sex' => 'male']);
        }

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['c6_23_new_sam_male']);
        $this->assertSame(1, $totals['c6_23_new_mam_male']);
        $this->assertSame(1, $totals['c6_23_new_normal_male']);
    }

    // -----------------------------------------------------------------
    // 5. Children: Oedema
    // -----------------------------------------------------------------

    public function test_a_child_with_oedema_is_counted_in_the_oedema_column_and_nowhere_else(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 130, 'sex' => 'female', 'has_oedema' => true]);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 130, 'sex' => 'female', 'has_oedema' => false]);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['c6_23_new_oedema_female']);
        $this->assertSame(1, $totals['c6_23_new_normal_female']);
        $this->assertSame(0, $totals['c6_23_new_sam_female']);
    }

    // -----------------------------------------------------------------
    // 6. Children: 6-59 months only
    // -----------------------------------------------------------------

    public function test_only_children_aged_6_to_59_months_at_the_visit_are_included(): void
    {
        foreach ([5, 6, 23, 24, 59, 60] as $months) {
            $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => $months, 'muac_mm' => 118, 'sex' => 'male']);
        }

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(2, $totals['c6_23_new_mam_male'], '6 and 23 months.');
        $this->assertSame(2, $totals['c24_59_new_mam_male'], '24 and 59 months.');

        $inScope = 0;

        foreach ($totals as $key => $value) {
            if (preg_match('/^c(6_23|24_59)_/', $key)) {
                $inScope += $value;
            }
        }

        $this->assertSame(4, $inScope, '5 and 60 months are outside the sheet.');
    }

    // -----------------------------------------------------------------
    // 7. Children: 6-59 months PWD
    // -----------------------------------------------------------------

    public function test_disabled_children_fill_the_pwd_block_by_status_and_sex(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 130, 'sex' => 'male', 'is_pwd' => true]);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'follow_up', 'age' => 36, 'muac_mm' => 118, 'sex' => 'female', 'is_pwd' => true]);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 36, 'muac_mm' => 110, 'sex' => 'male', 'is_pwd' => true]);
        // Not disabled: stays out of the PWD block.
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 110, 'sex' => 'male', 'is_pwd' => false]);
        // Disabled but 4 months old: outside 6-59, so outside the block.
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 4, 'muac_mm' => 110, 'sex' => 'male', 'is_pwd' => true]);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['pwd_normal_male']);
        $this->assertSame(1, $totals['pwd_mam_female']);
        $this->assertSame(1, $totals['pwd_sam_male']);
        $this->assertSame(0, $totals['pwd_sam_female']);
        $this->assertSame(0, $totals['pwd_normal_female']);
        $this->assertSame(0, $totals['pwd_mam_male']);

        // The PWD block is in addition to, not instead of, the child's own cell.
        $this->assertSame(2, $totals['c6_23_new_normal_male'] + $totals['c6_23_new_sam_male']);
    }

    // -----------------------------------------------------------------
    // 8-11. Pregnant / Breastfeeding, NEW / Follow-up
    // -----------------------------------------------------------------

    public function test_pregnant_new_women_are_counted_in_the_pregnant_new_block(): void
    {
        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 240]);

        $this->assertOnlyWomanCell('pw_new_not_wasted_a20p');
    }

    public function test_breastfeeding_new_women_are_counted_in_the_breastfeeding_new_block(): void
    {
        $this->woman(['date' => '2026-08-10', 'status_type' => 'lactating', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 240]);

        $this->assertOnlyWomanCell('bf_new_not_wasted_a20p');
    }

    public function test_pregnant_follow_up_women_are_counted_in_the_pregnant_follow_up_block(): void
    {
        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'follow_up', 'age' => 25, 'muac_mm' => 240]);

        $this->assertOnlyWomanCell('pw_fu_not_wasted_a20p');
    }

    public function test_breastfeeding_follow_up_women_are_counted_in_the_breastfeeding_follow_up_block(): void
    {
        $this->woman(['date' => '2026-08-10', 'status_type' => 'lactating', 'visit_type' => 'follow_up', 'age' => 25, 'muac_mm' => 240]);

        $this->assertOnlyWomanCell('bf_fu_not_wasted_a20p');
    }

    // -----------------------------------------------------------------
    // 12. Women: <18 / 18-19 / 20+ at the reporting date
    // -----------------------------------------------------------------

    public function test_women_age_groups_are_taken_from_dob_at_the_reporting_date(): void
    {
        // 17 years and 364 days: still under 18 on the reporting date.
        $this->womanBornOn('2026-08-10', '2008-08-11', 30);
        // Exactly 18 today.
        $this->womanBornOn('2026-08-10', '2008-08-10', 30);
        // 19 years and 364 days: still 18-19.
        $this->womanBornOn('2026-08-10', '2006-08-11', 30);
        // Exactly 20 today.
        $this->womanBornOn('2026-08-10', '2006-08-10', 30);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['pw_new_not_wasted_u18']);
        $this->assertSame(2, $totals['pw_new_not_wasted_a18_19']);
        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
    }

    private function womanBornOn(string $reportingDate, string $dob, int $storedAge): void
    {
        PregnantLactatingWoman::create($this->womanAttributes([
            'date_of_reporting' => $reportingDate,
            'date_of_birth' => $dob,
            'age_years' => $storedAge,
            'status_type' => 'pregnant',
            'visit_type' => 'new',
            'muac_mm' => 240,
        ]));
    }

    // -----------------------------------------------------------------
    // 13-14. Women: MUAC >=23 cm / <23 cm (230 mm, not 23 mm)
    // -----------------------------------------------------------------

    public function test_the_women_muac_threshold_is_230_mm(): void
    {
        foreach ([230, 240, 300] as $muac) {
            $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => $muac]);
        }

        // 229.9 mm and 100 mm are both under 23 cm. 25 mm - which a 23 mm
        // threshold would call normal - is under it too.
        foreach ([229.9, 100, 25] as $muac) {
            $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => $muac]);
        }

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(3, $totals['pw_new_not_wasted_a20p'], '>= 230 mm is the ">=23 cm" column.');
        $this->assertSame(3, $totals['pw_new_wasted_a20p'], '< 230 mm is the "<23 cm" column.');
    }

    // -----------------------------------------------------------------
    // 15. PBW-PWD
    // -----------------------------------------------------------------

    public function test_disabled_women_fill_the_pbw_pwd_block_by_the_same_threshold(): void
    {
        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 240, 'is_pwd' => true]);
        $this->woman(['date' => '2026-08-10', 'status_type' => 'lactating', 'visit_type' => 'follow_up', 'age' => 17, 'muac_mm' => 210, 'is_pwd' => true]);
        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 210, 'is_pwd' => false]);

        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        $this->assertSame(1, $totals['pbw_pwd_normal']);
        $this->assertSame(1, $totals['pbw_pwd_mam']);

        // The PBW-PWD block is in addition to the women's own cells.
        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame(1, $totals['bf_fu_wasted_u18']);
        $this->assertSame(1, $totals['pw_new_wasted_a20p']);
    }

    // -----------------------------------------------------------------
    // 16. Site type
    // -----------------------------------------------------------------

    public function test_screening_is_grouped_by_the_chosen_site_type(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male', 'type_of_site' => self::SITE]);
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male', 'type_of_site' => self::OTHER_SITE]);
        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 240, 'type_of_site' => self::OTHER_SITE]);

        $mine = $this->screening(self::AUGUST, self::AUGUST, self::SITE);
        $other = $this->screening(self::AUGUST, self::AUGUST, self::OTHER_SITE);
        $all = $this->screening(self::AUGUST, self::AUGUST, SiteVocabulary::ALL);

        $this->assertSame(1, $mine['totals']['c6_23_new_mam_male']);
        $this->assertSame(0, $mine['totals']['pw_new_not_wasted_a20p']);
        $this->assertSame(self::SITE, $mine['rows'][0]['mba']);

        $this->assertSame(1, $other['totals']['c6_23_new_mam_male']);
        $this->assertSame(1, $other['totals']['pw_new_not_wasted_a20p']);
        $this->assertSame(self::OTHER_SITE, $other['rows'][0]['mba']);

        $this->assertSame(2, $all['totals']['c6_23_new_mam_male']);
        $this->assertSame('All sites', $all['rows'][0]['mba']);
    }

    // -----------------------------------------------------------------
    // 17. Month and year
    // -----------------------------------------------------------------

    public function test_a_record_is_filed_under_the_month_and_year_of_its_reporting_date(): void
    {
        $this->child(['date' => '2026-08-31', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->child(['date' => '2026-09-01', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        // Same month, previous year: not this report's August.
        $this->child(['date' => '2025-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);

        $rows = collect($this->screening(self::AUGUST, self::SEPTEMBER)['rows']);

        $this->assertSame(1, $rows->firstWhere('month', 'August')['c6_23_new_mam_male']);
        $this->assertSame(1, $rows->firstWhere('month', 'September')['c6_23_new_mam_male']);
        $this->assertCount(2, $rows, 'August 2025 must not appear anywhere in an August-September 2026 report.');
    }

    public function test_a_record_is_filed_by_its_reporting_date_not_by_when_it_was_entered(): void
    {
        // Entered in September, but the screening happened in August.
        $child = $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        Child::withoutEvents(fn () => Child::query()->whereKey($child->id)->update(['created_at' => '2026-09-05 10:00:00']));

        $rows = collect($this->screening(self::AUGUST, self::SEPTEMBER)['rows']);

        $this->assertSame(1, $rows->firstWhere('month', 'August')['c6_23_new_mam_male']);
        $this->assertSame(0, $rows->firstWhere('month', 'September')['c6_23_new_mam_male']);
    }

    // -----------------------------------------------------------------
    // 18. Monthly totals
    // -----------------------------------------------------------------

    public function test_every_month_has_its_own_total_summed_from_its_own_rows_only(): void
    {
        // August: 10 + 20 + 5 = 35 across three days.
        foreach ([10 => 10, 12 => 20, 20 => 5] as $day => $count) {
            $this->children($count, ['date' => "2026-08-{$day}", 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        }

        // September: 15 + 10 + 7 = 32.
        foreach ([3 => 15, 14 => 10, 28 => 7] as $day => $count) {
            $this->children($count, ['date' => "2026-09-{$day}", 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        }

        // October: nothing at all.

        $sheet = $this->screening(self::AUGUST, self::OCTOBER);

        $this->assertSame([8, 9, 10], array_keys($sheet['monthTotals']));

        $this->assertSame(35, $sheet['monthTotals'][8]['c6_23_new_mam_male']);
        $this->assertSame(32, $sheet['monthTotals'][9]['c6_23_new_mam_male']);
        $this->assertSame(0, $sheet['monthTotals'][10]['c6_23_new_mam_male']);

        $this->assertSame('Total August', $sheet['monthTotals'][8]['mba']);
        $this->assertSame('Total September', $sheet['monthTotals'][9]['mba']);
        $this->assertSame('Total October', $sheet['monthTotals'][10]['mba']);

        // The period total is the sum of the monthly totals, and is never
        // passed off as any month's own.
        $this->assertSame(67, $sheet['totals']['c6_23_new_mam_male']);
        $this->assertSame('Total', $sheet['totals']['mba']);
    }

    public function test_a_monthly_total_aggregates_every_indicator_of_that_month(): void
    {
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->child(['date' => '2026-08-20', 'visit_type' => 'follow_up', 'age' => 36, 'muac_mm' => 110, 'sex' => 'female', 'is_pwd' => true]);
        $this->woman(['date' => '2026-08-15', 'status_type' => 'lactating', 'visit_type' => 'new', 'age' => 19, 'muac_mm' => 220, 'is_pwd' => true]);

        $this->child(['date' => '2026-09-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);

        $sheet = $this->screening(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][8];
        $september = $sheet['monthTotals'][9];

        $this->assertSame(1, $august['c6_23_new_mam_male']);
        $this->assertSame(1, $august['c24_59_fu_sam_female']);
        $this->assertSame(1, $august['pwd_sam_female']);
        $this->assertSame(1, $august['bf_new_wasted_a18_19']);
        $this->assertSame(1, $august['pbw_pwd_mam']);

        $this->assertSame(1, $september['c6_23_new_mam_male']);
        $this->assertSame(0, $september['c24_59_fu_sam_female']);
        $this->assertSame(0, $september['bf_new_wasted_a18_19']);

        // Every monthly total is the column-wise sum of that month's rows.
        foreach ($sheet['monthTotals'] as $month => $monthTotal) {
            $label = ReportPeriod::month(self::YEAR, $month)->monthLabel($month);
            $rows = array_filter($sheet['rows'], fn (array $row): bool => $row['month'] === $label);

            foreach (MealReportLayout::columns(MealReportLayout::SHEET_SCREENING) as $key) {
                if (in_array($key, ['mba', 'month', 'day'], true)) {
                    continue;
                }

                $this->assertSame(array_sum(array_column($rows, $key)), $monthTotal[$key], "[{$label}] total of [{$key}]");
            }
        }
    }

    // -----------------------------------------------------------------
    // 19. Multiple months in one workbook
    // -----------------------------------------------------------------

    public function test_all_months_are_written_into_one_workbook_each_followed_by_its_total(): void
    {
        $this->children(3, ['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->children(2, ['date' => '2026-08-20', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->children(4, ['date' => '2026-09-05', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);
        $this->children(1, ['date' => '2026-10-15', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);

        $book = $this->workbook($this->build(self::AUGUST, self::OCTOBER));

        // One workbook, the template's own sheets, no sheet per month.
        $this->assertSame(MealReportLayout::sheets(), $book->getSheetNames());

        $sheet = $book->getSheetByName(MealReportLayout::SHEET_SCREENING);
        $column = $this->column('c6_23_new_mam_male');
        $first = MealReportLayout::FIRST_DATA_ROW[MealReportLayout::SHEET_SCREENING];

        $expected = [
            [self::SITE, 'August', 10, 3],
            [self::SITE, 'August', 20, 2],
            ['Total August', '', '', 5],
            [self::SITE, 'September', 5, 4],
            ['Total September', '', '', 4],
            [self::SITE, 'October', 15, 1],
            ['Total October', '', '', 1],
            ['Total', '', '', 10],
        ];

        foreach ($expected as $offset => [$mba, $month, $day, $count]) {
            $row = $first + $offset;

            $this->assertSame($mba, (string) $sheet->getCell([1, $row])->getValue(), "Row {$row}: MBA");
            $this->assertSame($month, (string) $sheet->getCell([2, $row])->getValue(), "Row {$row}: MONTH");
            $this->assertSame((string) $day, (string) $sheet->getCell([3, $row])->getValue(), "Row {$row}: DAY");
            $this->assertSame($count, (int) $sheet->getCell([$column, $row])->getValue(), "Row {$row}: count");
        }

        // Nothing after the period total.
        $this->assertSame($first + count($expected) - 1, $sheet->getHighestDataRow());

        // Each total row is set apart: bold, and its label spanning MBA to DAY.
        foreach ([2, 4, 6, 7] as $offset) {
            $row = $first + $offset;

            $this->assertTrue($sheet->getStyle("A{$row}")->getFont()->getBold(), "Row {$row} should be bold.");
            $this->assertContains("A{$row}:C{$row}", array_keys($sheet->getMergeCells()));
        }

        // Day rows are not.
        $this->assertFalse($sheet->getStyle('A' . $first)->getFont()->getBold());
    }

    public function test_a_single_month_export_closes_with_that_months_total(): void
    {
        $this->children(2, ['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male']);

        $sheet = $this->workbook($this->build(self::AUGUST, self::AUGUST))->getSheetByName(MealReportLayout::SHEET_SCREENING);
        $first = MealReportLayout::FIRST_DATA_ROW[MealReportLayout::SHEET_SCREENING];

        $this->assertSame('August', (string) $sheet->getCell([2, $first])->getValue());
        $this->assertSame('Total August', (string) $sheet->getCell([1, $first + 1])->getValue());
        $this->assertSame(2, (int) $sheet->getCell([$this->column('c6_23_new_mam_male'), $first + 1])->getValue());

        // One month has one total; it is not repeated as a period total.
        $this->assertSame($first + 1, $sheet->getHighestDataRow());
    }

    public function test_every_sheet_of_the_workbook_carries_the_monthly_totals(): void
    {
        $book = $this->workbook($this->build(self::AUGUST, self::SEPTEMBER));

        foreach (MealReportLayout::sheets() as $name) {
            $sheet = $book->getSheetByName($name);
            $first = MealReportLayout::FIRST_DATA_ROW[$name];

            $labels = [];

            for ($row = $first; $row <= $sheet->getHighestDataRow(); $row++) {
                $labels[] = (string) $sheet->getCell([1, $row])->getValue();
            }

            $this->assertSame(
                [self::SITE, 'Total August', self::SITE, 'Total September', 'Total'],
                $labels,
                "Sheet [{$name}] is missing a monthly total.",
            );
        }
    }

    // -----------------------------------------------------------------
    // 20. No double counting
    // -----------------------------------------------------------------

    public function test_each_screening_visit_is_counted_exactly_once(): void
    {
        // The same child, screened once as new and twice on follow-up, with
        // the same ID every time. Three visits, three counts, each in its own
        // cell - never three in the new cell, never six in all.
        $this->child(['date' => '2026-08-10', 'visit_type' => 'new', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male', 'child_id' => '111111111']);
        $this->child(['date' => '2026-08-24', 'visit_type' => 'follow_up', 'age' => 12, 'muac_mm' => 118, 'sex' => 'male', 'child_id' => '111111111']);
        $this->child(['date' => '2026-09-07', 'visit_type' => 'follow_up', 'age' => 13, 'muac_mm' => 130, 'sex' => 'male', 'child_id' => '111111111']);

        $this->woman(['date' => '2026-08-10', 'status_type' => 'pregnant', 'visit_type' => 'new', 'age' => 25, 'muac_mm' => 240, 'is_pwd' => true, 'mother_id' => '222222222']);
        $this->woman(['date' => '2026-09-10', 'status_type' => 'pregnant', 'visit_type' => 'follow_up', 'age' => 25, 'muac_mm' => 220, 'is_pwd' => true, 'mother_id' => '222222222']);

        $sheet = $this->screening(self::AUGUST, self::SEPTEMBER);
        $totals = $sheet['totals'];

        $this->assertSame(1, $totals['c6_23_new_mam_male']);
        $this->assertSame(1, $totals['c6_23_fu_mam_male']);
        $this->assertSame(1, $totals['c6_23_fu_normal_male']);
        $this->assertSame(3, $this->sum($totals, '/^c(6_23|24_59)_/'), 'Three visits, three counts.');

        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame(1, $totals['pw_fu_wasted_a20p']);
        $this->assertSame(2, $this->sum($totals, '/^(pw|bf)_(new|fu)_/'), 'Two visits, two counts.');
        $this->assertSame(1, $totals['pbw_pwd_normal']);
        $this->assertSame(1, $totals['pbw_pwd_mam']);

        // And the monthly totals add up to the period total, no more.
        $this->assertSame(
            $this->sum($totals, '/^c(6_23|24_59)_/'),
            $this->sum($sheet['monthTotals'][8], '/^c(6_23|24_59)_/') + $this->sum($sheet['monthTotals'][9], '/^c(6_23|24_59)_/'),
        );
    }

    // -----------------------------------------------------------------
    // Design: the PBW MUAC headers
    // -----------------------------------------------------------------

    public function test_the_pbw_muac_headers_read_23_cm_without_the_wasted_wording(): void
    {
        $sheet = $this->workbook($this->build(self::AUGUST, self::AUGUST))->getSheetByName(MealReportLayout::SHEET_SCREENING);
        $leafRow = MealReportLayout::LEAF_ROW[MealReportLayout::SHEET_SCREENING];
        $width = count(MealReportLayout::columns(MealReportLayout::SHEET_SCREENING));

        // The four women's blocks each carry the pair, in this order.
        $muacHeaders = [];

        for ($column = 36; $column <= 59; $column++) {
            $value = (string) $sheet->getCell([$column, 4])->getValue();

            if ($value !== '') {
                $muacHeaders[] = $value;
            }
        }

        $this->assertSame(array_merge(...array_fill(0, 4, ['≥23 cm', '<23 cm'])), $muacHeaders);

        // Nowhere in the header block does "wasted" survive.
        for ($row = 2; $row <= $leafRow; $row++) {
            for ($column = 1; $column <= $width; $column++) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'wasted',
                    (string) $sheet->getCell([$column, $row])->getValue(),
                    "Header at column {$column}, row {$row} still says wasted.",
                );
            }
        }

        // The structure beneath is untouched: the merged pair still spans
        // three age columns each, captioned <18 / 18-19 / 20+.
        $merges = array_keys($sheet->getMergeCells());

        foreach ([36, 39, 42, 45, 48, 51, 54, 57] as $start) {
            $from = $sheet->getCell([$start, 4])->getColumn();
            $to = $sheet->getCell([$start + 2, 4])->getColumn();

            $this->assertContains("{$from}4:{$to}4", $merges);

            $this->assertSame(
                ['<18 years', '18-19 years', '20+ years'],
                [
                    (string) $sheet->getCell([$start, $leafRow])->getValue(),
                    (string) $sheet->getCell([$start + 1, $leafRow])->getValue(),
                    (string) $sheet->getCell([$start + 2, $leafRow])->getValue(),
                ],
            );
        }

        // The block captions above are as they were.
        $this->assertSame('Pregnant women (NEW)', (string) $sheet->getCell([36, 3])->getValue());
        $this->assertSame('Breastfeeding women (NEW)', (string) $sheet->getCell([42, 3])->getValue());
        $this->assertSame('Pregnant women (Follow up)', (string) $sheet->getCell([48, 3])->getValue());
        $this->assertSame('Breastfeeding women (Follow up)', (string) $sheet->getCell([54, 3])->getValue());
        $this->assertSame('6-59 months - PWD', (string) $sheet->getCell([60, 3])->getValue());
        $this->assertSame('PBW-PWD', (string) $sheet->getCell([66, 3])->getValue());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array<string, array> */
    private function build(int $from, int $to, ?string $site = self::SITE): array
    {
        return app(MealReportService::class)->buildPeriod(ReportPeriod::make(self::YEAR, $from, $to), $site);
    }

    /** @return array{rows: array, totals: array, monthTotals: array, monthStarts: array, review: array} */
    private function screening(int $from, int $to, ?string $site = self::SITE): array
    {
        return $this->build($from, $to, $site)[MealReportLayout::SHEET_SCREENING];
    }

    private function workbook(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'meal') . '.xlsx';
        file_put_contents($path, Excel::raw(new MealReportExport($data), \Maatwebsite\Excel\Excel::XLSX));

        $book = IOFactory::load($path);

        @unlink($path);

        return $book;
    }

    /** 1-based sheet column of a Screening key. */
    private function column(string $key): int
    {
        return array_search($key, MealReportLayout::columns(MealReportLayout::SHEET_SCREENING), true) + 1;
    }

    /** Sum of every numeric cell whose key matches the pattern. */
    private function sum(array $row, string $pattern): int
    {
        $total = 0;

        foreach ($row as $key => $value) {
            if (is_numeric($value) && preg_match($pattern, $key)) {
                $total += (int) $value;
            }
        }

        return $total;
    }

    /** The one woman on file lands in this cell and in no other women's cell. */
    private function assertOnlyWomanCell(string $expected): void
    {
        $totals = $this->screening(self::AUGUST, self::AUGUST)['totals'];

        foreach ($totals as $key => $value) {
            if (! preg_match('/^(pw|bf)_(new|fu)_/', $key)) {
                continue;
            }

            $this->assertSame($key === $expected ? 1 : 0, $value, "Unexpected count in [{$key}].");
        }
    }

    private function children(int $count, array $attributes): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->child($attributes);
        }
    }

    private function child(array $attributes): Child
    {
        $date = $attributes['date'];

        return Child::create($this->childAttributes([
            'visit_type' => $attributes['visit_type'],
            'child_id' => $attributes['child_id'] ?? (string) fake()->unique()->numberBetween(100000000, 999999999),
            'date_of_reporting' => $date,
            'sex' => $attributes['sex'],
            'date_of_birth' => Carbon::parse($date)->subMonths($attributes['age'])->toDateString(),
            'muac_mm' => $attributes['muac_mm'],
            'has_oedema' => $attributes['has_oedema'] ?? false,
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'type_of_site' => $attributes['type_of_site'] ?? self::SITE,
        ]));
    }

    /** @return array<string, mixed> */
    private function childAttributes(array $overrides): array
    {
        return array_merge([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'governorate' => 'gaza',
            'type_of_site' => self::SITE,
            'has_oedema' => false,
            'is_pwd' => false,
        ], $overrides);
    }

    private function woman(array $attributes): PregnantLactatingWoman
    {
        $date = $attributes['date'];

        return PregnantLactatingWoman::create($this->womanAttributes([
            'visit_type' => $attributes['visit_type'],
            'mother_id' => $attributes['mother_id'] ?? (string) fake()->unique()->numberBetween(100000000, 999999999),
            'date_of_reporting' => $date,
            'date_of_birth' => Carbon::parse($date)->subYears($attributes['age'])->toDateString(),
            'status_type' => $attributes['status_type'],
            'muac_mm' => $attributes['muac_mm'],
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'type_of_site' => $attributes['type_of_site'] ?? self::SITE,
        ]));
    }

    /** @return array<string, mixed> */
    private function womanAttributes(array $overrides): array
    {
        return array_merge([
            'visit_type' => 'new',
            'full_name_ar' => 'اختبار',
            'mother_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'governorate' => 'gaza',
            'type_of_site' => self::SITE,
            'is_pwd' => false,
        ], $overrides);
    }
}
