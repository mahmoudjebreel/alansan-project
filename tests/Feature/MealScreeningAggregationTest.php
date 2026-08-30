<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\SiteVocabulary;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cell-by-cell verification of the Screening sheet.
 *
 * Every assertion compares an aggregated cell against a second, independent
 * count taken straight off the table with plain column predicates - raw MUAC
 * ranges, a raw date-of-birth window, the raw enum values. Nothing in that
 * manual path goes through the service, the layout or the classifiers, so a
 * cell can only pass if the aggregation really did intersect visit type, age
 * band and nutrition status on the same record.
 */
class MealScreeningAggregationTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 7;

    private const SITE = 'Mossab Camp';

    private const OTHER_SITE = 'El Salam Camp';

    /** Two reporting days, so the day rows are exercised as well as the totals. */
    private const DAYS = [10, 20];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Children: visit type x age band x MUAC/FI x sex
    // -----------------------------------------------------------------

    public function test_every_child_screening_cell_matches_a_manual_database_count(): void
    {
        $plan = $this->childPlan();

        $this->seedChildren($plan, self::MONTH, self::SITE);
        $this->seedChildNoise();

        $totals = $this->totals(self::MONTH, self::SITE);

        foreach ($plan as $key => $spec) {
            $this->assertSame(
                $spec['count'],
                $totals[$key],
                "Cell [{$key}] does not hold the number of records that were created for it.",
            );

            $this->assertSame(
                $this->manualChildCount($spec, self::MONTH, self::SITE),
                $totals[$key],
                "Cell [{$key}] disagrees with a manual database count on the same three conditions.",
            );
        }
    }

    public function test_a_child_is_counted_in_exactly_one_screening_cell(): void
    {
        $plan = $this->childPlan();

        $this->seedChildren($plan, self::MONTH, self::SITE);
        $this->seedChildNoise();

        // The two out-of-band children in the noise are not on the sheet at all.
        $expected = Child::query()
            ->whereBetween('date_of_reporting', $this->monthWindow(self::MONTH))
            ->where('type_of_site', self::SITE)
            ->count() - 2;

        $counted = 0;

        foreach ($this->totals(self::MONTH, self::SITE) as $key => $value) {
            if (preg_match('/^(c6_23|c24_59)_/', $key) === 1) {
                $counted += (int) $value;
            }
        }

        $this->assertSame($expected, $counted, 'The child block must count each screened child once and only once.');
    }

    public function test_children_are_split_across_the_day_rows_they_were_reported_on(): void
    {
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 20]);
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 20]);

        $rows = collect($this->sheet(self::MONTH, self::SITE)['rows'])->keyBy('day');

        $this->assertSame(1, $rows[10]['c6_23_new_mam_male']);
        $this->assertSame(2, $rows[20]['c6_23_new_mam_male']);
        $this->assertSame(3, $this->totals(self::MONTH, self::SITE)['c6_23_new_mam_male']);
    }

    public function test_the_age_band_is_taken_from_date_of_birth_at_the_reporting_date(): void
    {
        // 23 months and 24 months old on the day each child was screened.
        $this->child(['visit' => 'new', 'age_months' => 23, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
        $this->child(['visit' => 'new', 'age_months' => 24, 'muac' => 118, 'sex' => 'male', 'day' => 10]);

        // A stale manual age must not override the date of birth.
        $this->child([
            'visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'female', 'day' => 10,
            'stored_age_months' => 40,
        ]);

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(1, $totals['c6_23_new_mam_male']);
        $this->assertSame(1, $totals['c24_59_new_mam_male']);
        $this->assertSame(1, $totals['c6_23_new_mam_female']);
        $this->assertSame(0, $totals['c24_59_new_mam_female']);
    }

    public function test_the_manual_age_is_only_used_when_there_is_no_date_of_birth(): void
    {
        $child = $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
        $child->forceFill(['date_of_birth' => null, 'age_months' => 30])->save();

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(1, $totals['c24_59_new_mam_male']);
        $this->assertSame(0, $totals['c6_23_new_mam_male']);
    }

    public function test_disabled_children_are_counted_in_the_pwd_block_whatever_their_status(): void
    {
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 130, 'sex' => 'male', 'day' => 10, 'is_pwd' => true]);
        $this->child(['visit' => 'fu', 'age_months' => 36, 'muac' => 118, 'sex' => 'female', 'day' => 10, 'is_pwd' => true]);
        $this->child(['visit' => 'new', 'age_months' => 36, 'muac' => 110, 'sex' => 'male', 'day' => 10, 'is_pwd' => true]);
        // No Oedema column exists in the PWD block, and oedema is SAM.
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 132, 'sex' => 'male', 'day' => 10, 'is_pwd' => true, 'oedema' => true]);
        // Not disabled, so absent from the block entirely.
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 110, 'sex' => 'female', 'day' => 10]);

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(1, $totals['pwd_normal_male']);
        $this->assertSame(1, $totals['pwd_mam_female']);
        $this->assertSame(2, $totals['pwd_sam_male']);
        $this->assertSame(0, $totals['pwd_sam_female']);

        $disabled = Child::query()
            ->whereBetween('date_of_reporting', $this->monthWindow(self::MONTH))
            ->where('type_of_site', self::SITE)
            ->where('is_pwd', true)
            ->count();

        $counted = 0;

        foreach ($totals as $key => $value) {
            if (str_starts_with($key, 'pwd_')) {
                $counted += (int) $value;
            }
        }

        $this->assertSame($disabled, $counted, 'Every disabled child screened must appear once in the PWD block.');
    }

    // -----------------------------------------------------------------
    // Women: visit type x age bracket x wasting x pregnant/breastfeeding
    // -----------------------------------------------------------------

    public function test_every_woman_screening_cell_matches_a_manual_database_count(): void
    {
        $plan = $this->womanPlan();

        $this->seedWomen($plan, self::MONTH, self::SITE);
        $this->seedWomanNoise();

        $totals = $this->totals(self::MONTH, self::SITE);

        foreach ($plan as $key => $spec) {
            $this->assertSame(
                $spec['count'],
                $totals[$key],
                "Cell [{$key}] does not hold the number of records that were created for it.",
            );

            $this->assertSame(
                $this->manualWomanCount($spec, self::MONTH, self::SITE),
                $totals[$key],
                "Cell [{$key}] disagrees with a manual database count on the same conditions.",
            );
        }
    }

    public function test_a_woman_is_counted_in_exactly_one_screening_cell(): void
    {
        $plan = $this->womanPlan();

        $this->seedWomen($plan, self::MONTH, self::SITE);
        $this->seedWomanNoise();

        $expected = PregnantLactatingWoman::query()
            ->whereBetween('date_of_reporting', $this->monthWindow(self::MONTH))
            ->where('type_of_site', self::SITE)
            ->count();

        $counted = 0;

        foreach ($this->totals(self::MONTH, self::SITE) as $key => $value) {
            if (preg_match('/^(pw|bf)_(new|fu)_/', $key) === 1) {
                $counted += (int) $value;
            }
        }

        $this->assertSame($expected, $counted, 'The PBW block must count each screened woman once and only once.');
    }

    public function test_pregnant_and_breastfeeding_women_are_kept_in_separate_blocks(): void
    {
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 240, 'age_years' => 25, 'day' => 10]);
        $this->woman(['status' => 'lactating', 'visit' => 'new', 'muac' => 240, 'age_years' => 25, 'day' => 10]);

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame(1, $totals['bf_new_not_wasted_a20p']);
    }

    public function test_the_womans_threshold_is_independent_of_the_child_thresholds(): void
    {
        // 229mm is Normal on the child scale and wasted on the 23cm PBW scale;
        // 120mm is SAM on the child scale and simply wasted here.
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 229, 'age_years' => 25, 'day' => 10]);
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 120, 'age_years' => 25, 'day' => 10]);
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 230, 'age_years' => 25, 'day' => 10]);

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(2, $totals['pw_new_wasted_a20p']);
        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
        $this->assertSame('Normal', Child::classifyMuac(229));
        $this->assertSame('Malnourished', PregnantLactatingWoman::classifyMuac(229));
    }

    public function test_the_age_bracket_is_taken_from_the_mothers_date_of_birth_at_the_reporting_date(): void
    {
        $reference = Carbon::create(self::YEAR, self::MONTH, 10);

        foreach ([
            $reference->copy()->subYears(18)->addDay(),
            $reference->copy()->subYears(18),
            $reference->copy()->subYears(20),
            // 19 years and 364 days is still the 18-19 bracket.
            $reference->copy()->subYears(20)->addDay(),
        ] as $dob) {
            $this->woman([
                'status' => 'pregnant', 'visit' => 'new', 'muac' => 240, 'day' => 10, 'dob' => $dob->toDateString(),
            ]);
        }

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(1, $totals['pw_new_not_wasted_u18']);
        $this->assertSame(2, $totals['pw_new_not_wasted_a18_19']);
        $this->assertSame(1, $totals['pw_new_not_wasted_a20p']);
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function test_changing_the_month_filter_recomputes_every_cell(): void
    {
        $plan = $this->childPlan();

        $this->seedChildren($plan, self::MONTH, self::SITE);
        $this->seedWomen($this->womanPlan(), self::MONTH, self::SITE);
        $this->seedChildNoise();
        $this->seedWomanNoise();

        $july = $this->totals(self::MONTH, self::SITE);
        $june = $this->totals(self::MONTH - 1, self::SITE);

        foreach ($plan as $key => $spec) {
            $this->assertSame($spec['count'], $july[$key]);
            $this->assertSame($this->manualChildCount($spec, self::MONTH - 1, self::SITE), $june[$key]);
        }

        $this->assertNotSame($july, $june, 'A different month must produce a different set of numbers.');
        $this->assertSame([], $this->sheet(self::MONTH - 2, self::SITE)['rows'], 'An empty month has no day rows.');
    }

    public function test_changing_the_site_filter_recomputes_every_cell(): void
    {
        $plan = $this->childPlan();

        $this->seedChildren($plan, self::MONTH, self::SITE);
        $this->seedWomen($this->womanPlan(), self::MONTH, self::SITE);
        $this->seedChildNoise();
        $this->seedWomanNoise();

        $other = $this->totals(self::MONTH, self::OTHER_SITE);
        $all = $this->totals(self::MONTH, SiteVocabulary::ALL);

        foreach ($plan as $key => $spec) {
            $this->assertSame(
                $this->manualChildCount($spec, self::MONTH, self::OTHER_SITE),
                $other[$key],
                "Cell [{$key}] is wrong once the site filter moves to the other camp.",
            );

            $this->assertSame(
                $this->manualChildCount($spec, self::MONTH, null),
                $all[$key],
                "Cell [{$key}] is wrong when every site is included.",
            );
        }
    }

    /**
     * The dimensions have to be intersected inside one grouped query. Counting
     * each of them in a pass of its own would give totals that look right and
     * cells that do not.
     */
    public function test_each_module_is_aggregated_in_a_single_grouped_query(): void
    {
        $this->seedChildren($this->childPlan(), self::MONTH, self::SITE);
        $this->seedWomen($this->womanPlan(), self::MONTH, self::SITE);

        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(MealReportService::class)->build(self::YEAR, self::MONTH, self::SITE);

        $against = fn (string $table): array => array_values(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, "from \"{$table}\"") || str_contains($sql, "from `{$table}`"),
        ));

        $children = $against('children');
        $women = $against('pregnant_lactating_women');

        $this->assertCount(1, $children, 'The children are read once, not once per dimension.');
        $this->assertCount(1, $women, 'The women are read once, not once per dimension.');

        foreach (['visit_type', 'sex', 'has_oedema', 'muac_mm', 'date_of_birth'] as $dimension) {
            $this->assertStringContainsString($dimension, explode('group by', $children[0])[1] ?? '');
        }

        foreach (['visit_type', 'status_type', 'muac_mm', 'date_of_birth'] as $dimension) {
            $this->assertStringContainsString($dimension, explode('group by', $women[0])[1] ?? '');
        }

        // The month and the site are narrowed before the grouping, not after.
        foreach ([$children[0], $women[0]] as $sql) {
            $this->assertTrue(
                strpos($sql, 'date_of_reporting') < strpos($sql, 'group by'),
                'The filters must be applied in the same query that does the grouping.',
            );
            $this->assertStringContainsString('type_of_site', explode('group by', $sql)[0]);
        }
    }

    public function test_soft_deleted_records_are_left_out_of_every_cell(): void
    {
        $child = $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
        $woman = $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 220, 'age_years' => 25, 'day' => 10]);

        $this->assertSame(1, $this->totals(self::MONTH, self::SITE)['c6_23_new_mam_male']);

        $child->delete();
        $woman->delete();

        $totals = $this->totals(self::MONTH, self::SITE);

        $this->assertSame(0, $totals['c6_23_new_mam_male']);
        $this->assertSame(0, $totals['pw_new_wasted_a20p']);
    }

    // -----------------------------------------------------------------
    // Plans
    // -----------------------------------------------------------------

    /**
     * One entry per child cell of the Screening sheet: the full cross product
     * of visit type, age band, nutrition status and sex, each with its own
     * record count so no two cells can be mistaken for one another.
     *
     * @return array<string, array<string, mixed>>
     */
    private function childPlan(): array
    {
        $bands = ['c6_23' => 12, 'c24_59' => 36];
        $statuses = [
            'normal' => ['muac' => 130, 'oedema' => false],
            'mam' => ['muac' => 118, 'oedema' => false],
            'sam' => ['muac' => 110, 'oedema' => false],
            'oedema' => ['muac' => 132, 'oedema' => true],
        ];

        $plan = [];
        $index = 0;

        foreach (['new' => 'new', 'fu' => 'follow_up'] as $visitKey => $visit) {
            foreach ($bands as $bandKey => $ageMonths) {
                foreach ($statuses as $statusKey => $status) {
                    foreach (['male', 'female'] as $sex) {
                        $plan["{$bandKey}_{$visitKey}_{$statusKey}_{$sex}"] = [
                            'visit' => $visit,
                            'band' => $bandKey,
                            'age_months' => $ageMonths,
                            'status' => $statusKey,
                            'muac' => $status['muac'],
                            'oedema' => $status['oedema'],
                            'sex' => $sex,
                            'count' => 1 + ($index++ % 3),
                        ];
                    }
                }
            }
        }

        return $plan;
    }

    /**
     * One entry per PBW cell: pregnant / breastfeeding, visit type, wasting
     * and age bracket.
     *
     * @return array<string, array<string, mixed>>
     */
    private function womanPlan(): array
    {
        $plan = [];
        $index = 0;

        foreach (['pw' => 'pregnant', 'bf' => 'lactating'] as $groupKey => $status) {
            foreach (['new' => 'new', 'fu' => 'follow_up'] as $visitKey => $visit) {
                foreach (['not_wasted' => 240, 'wasted' => 220] as $wastingKey => $muac) {
                    foreach (['u18' => 16, 'a18_19' => 19, 'a20p' => 25] as $bracketKey => $ageYears) {
                        $plan["{$groupKey}_{$visitKey}_{$wastingKey}_{$bracketKey}"] = [
                            'status' => $status,
                            'visit' => $visit,
                            'wasting' => $wastingKey,
                            'muac' => $muac,
                            'bracket' => $bracketKey,
                            'age_years' => $ageYears,
                            'count' => 1 + ($index++ % 3),
                        ];
                    }
                }
            }
        }

        return $plan;
    }

    /** @param  array<string, array<string, mixed>>  $plan */
    private function seedChildren(array $plan, int $month, string $site): void
    {
        foreach ($plan as $spec) {
            for ($n = 0; $n < $spec['count']; $n++) {
                $this->child($spec + [
                    'day' => self::DAYS[$n % count(self::DAYS)],
                    'month' => $month,
                    'site' => $site,
                ]);
            }
        }
    }

    /** @param  array<string, array<string, mixed>>  $plan */
    private function seedWomen(array $plan, int $month, string $site): void
    {
        foreach ($plan as $spec) {
            for ($n = 0; $n < $spec['count']; $n++) {
                $this->woman($spec + [
                    'day' => self::DAYS[$n % count(self::DAYS)],
                    'month' => $month,
                    'site' => $site,
                ]);
            }
        }
    }

    /** Records that must never land in the month and site under test. */
    private function seedChildNoise(): void
    {
        // Same shapes one month earlier, and at the other camp.
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10, 'month' => self::MONTH - 1]);
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10, 'month' => self::MONTH - 1]);
        $this->child(['visit' => 'follow_up', 'age_months' => 36, 'muac' => 110, 'sex' => 'female', 'day' => 20, 'month' => self::MONTH - 1]);
        $this->child(['visit' => 'new', 'age_months' => 12, 'muac' => 118, 'sex' => 'male', 'day' => 10, 'site' => self::OTHER_SITE]);
        $this->child(['visit' => 'follow_up', 'age_months' => 36, 'muac' => 130, 'sex' => 'female', 'day' => 10, 'site' => self::OTHER_SITE]);

        // Outside the 6-59 month window the sheet covers.
        $this->child(['visit' => 'new', 'age_months' => 3, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
        $this->child(['visit' => 'new', 'age_months' => 70, 'muac' => 118, 'sex' => 'male', 'day' => 10]);
    }

    private function seedWomanNoise(): void
    {
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 220, 'age_years' => 25, 'day' => 10, 'month' => self::MONTH - 1]);
        $this->woman(['status' => 'lactating', 'visit' => 'follow_up', 'muac' => 240, 'age_years' => 17, 'day' => 20, 'month' => self::MONTH - 1]);
        $this->woman(['status' => 'pregnant', 'visit' => 'new', 'muac' => 240, 'age_years' => 25, 'day' => 10, 'site' => self::OTHER_SITE]);
    }

    // -----------------------------------------------------------------
    // The manual counts the report is checked against
    // -----------------------------------------------------------------

    /**
     * Count the children a data officer would find by filtering the table by
     * hand on the same conditions - no classifier, no service, no layout.
     *
     * @param  array<string, mixed>  $spec
     */
    private function manualChildCount(array $spec, int $month, ?string $site): int
    {
        [$low, $high] = $spec['band'] === 'c6_23' ? [6, 23] : [24, 59];
        $total = 0;

        foreach (self::DAYS as $day) {
            $reference = Carbon::create(self::YEAR, $month, $day);

            $query = Child::query()
                ->whereDate('date_of_reporting', $reference->toDateString())
                ->when($site !== null, fn ($q) => $q->where('type_of_site', $site))
                ->where('visit_type', $spec['visit'])
                ->where('sex', $spec['sex'])
                ->whereBetween('date_of_birth', [
                    $reference->copy()->subMonths($high + 1)->addDay()->toDateString(),
                    $reference->copy()->subMonths($low)->toDateString(),
                ]);

            if ($spec['status'] === 'oedema') {
                $query->where('has_oedema', true);
            } else {
                $query->where('has_oedema', false);

                match ($spec['status']) {
                    'sam' => $query->where('muac_mm', '<=', 115),
                    'mam' => $query->where('muac_mm', '>', 115)->where('muac_mm', '<', 125),
                    'normal' => $query->where('muac_mm', '>=', 125),
                };
            }

            $total += $query->count();
        }

        return $total;
    }

    /** @param  array<string, mixed>  $spec */
    private function manualWomanCount(array $spec, int $month, ?string $site): int
    {
        $total = 0;

        foreach (self::DAYS as $day) {
            $reference = Carbon::create(self::YEAR, $month, $day);

            $query = PregnantLactatingWoman::query()
                ->whereDate('date_of_reporting', $reference->toDateString())
                ->when($site !== null, fn ($q) => $q->where('type_of_site', $site))
                ->where('status_type', $spec['status'])
                ->where('visit_type', $spec['visit'])
                ->where('muac_mm', $spec['wasting'] === 'wasted' ? '<' : '>=', 230);

            match ($spec['bracket']) {
                'u18' => $query->where('date_of_birth', '>', $reference->copy()->subYears(18)->toDateString()),
                'a18_19' => $query
                    ->where('date_of_birth', '<=', $reference->copy()->subYears(18)->toDateString())
                    ->where('date_of_birth', '>', $reference->copy()->subYears(20)->toDateString()),
                'a20p' => $query->where('date_of_birth', '<=', $reference->copy()->subYears(20)->toDateString()),
            };

            $total += $query->count();
        }

        return $total;
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param  array<string, mixed>  $attributes */
    private function child(array $attributes): Child
    {
        $reference = Carbon::create(self::YEAR, $attributes['month'] ?? self::MONTH, $attributes['day']);

        return Child::create([
            'visit_type' => $attributes['visit'] ?? 'new',
            'name' => 'Test child',
            'child_id' => (string) (900000000 + $this->sequence++),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $reference->toDateString(),
            'sex' => $attributes['sex'] ?? 'male',
            'date_of_birth' => $reference->copy()->subMonths($attributes['age_months'])->toDateString(),
            'age_months' => $attributes['stored_age_months'] ?? $attributes['age_months'],
            'muac_mm' => $attributes['muac'],
            'has_oedema' => $attributes['oedema'] ?? false,
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'governorate' => 'gaza',
            'type_of_site' => $attributes['site'] ?? self::SITE,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function woman(array $attributes): PregnantLactatingWoman
    {
        $reference = Carbon::create(self::YEAR, $attributes['month'] ?? self::MONTH, $attributes['day']);

        return PregnantLactatingWoman::create([
            'visit_type' => $attributes['visit'] ?? 'new',
            'full_name_ar' => 'اختبار',
            'mother_id' => (string) (800000000 + $this->sequence++),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $reference->toDateString(),
            'date_of_birth' => $attributes['dob'] ?? $reference->copy()->subYears($attributes['age_years'])->toDateString(),
            'age_years' => $attributes['age_years'] ?? null,
            'status_type' => $attributes['status'],
            'muac_mm' => $attributes['muac'],
            'is_pwd' => $attributes['is_pwd'] ?? false,
            'governorate' => 'gaza',
            'type_of_site' => $attributes['site'] ?? self::SITE,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    private function sheet(int $month, ?string $site): array
    {
        return app(MealReportService::class)
            ->build(self::YEAR, $month, $site)[MealReportLayout::SHEET_SCREENING];
    }

    /** @return array<string, int|float|string|null> */
    private function totals(int $month, ?string $site): array
    {
        return $this->sheet($month, $site)['totals'];
    }

    /** @return array<int, string> */
    private function monthWindow(int $month): array
    {
        $start = Carbon::create(self::YEAR, $month, 1);

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
