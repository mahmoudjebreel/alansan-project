<?php

namespace Tests\Feature;

use App\Models\FollowUpChild;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every finalized rule, for every combination: the outcome of the episode a
 * return follows (every closing outcome, an open one, none) x how that
 * episode was admitted (SAM, MAM, neither) x how the return is admitted
 * (SAM, MAM, neither), linked and unlinked.
 *
 * The PHP reading (classifyReturn()) and the SQL reading every screen,
 * filter, export and report uses must give the same answer, and that answer
 * must be the finalized rule; MEAL must count each in its column.
 */
class ClassificationRulesMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ADMITTED = ['SAM', 'MAM', null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_php_and_sql_agree_with_the_finalized_rules_for_every_combination(): void
    {
        $outcomes = [...FollowUpChild::CLOSING_OUTCOMES, FollowUpChild::ACTIVE_OUTCOME];
        $cases = [];
        $n = 0;

        foreach ($outcomes as $outcome) {
            foreach (self::ADMITTED as $before) {
                foreach (self::ADMITTED as $after) {
                    foreach ([true, false] as $linked) {
                        $idNumber = (string) (461000000 + $n++);

                        $previous = $this->episode($idNumber, [
                            'admitted_with' => $before,
                            'admission_date' => '2026-01-05',
                            'discharge_date' => $outcome === FollowUpChild::ACTIVE_OUTCOME ? null : '2026-02-01',
                            'discharge_outcome' => $outcome,
                        ]);

                        $return = $this->episode($idNumber, [
                            'admitted_with' => $after,
                            'admission_date' => '2026-03-01',
                            'previous_follow_up_child_id' => $linked ? $previous->getKey() : null,
                        ]);

                        $cases[] = [$return, $this->expected($outcome, $before, $after), $outcome, $before, $after, $linked];
                    }
                }
            }
        }

        foreach ($cases as [$return, $expected, $outcome, $before, $after, $linked]) {
            $label = sprintf('[%s, %s -> %s, %s]', $outcome, $before ?? 'none', $after ?? 'none', $linked ? 'linked' : 'unlinked');

            $sql = FollowUpChild::whereKey($return->getKey())->withAdmissionClassification()->first()->readmissionClassification();

            // An open episode is not followed by the inference, and a link to
            // one closes nothing, so PHP reads it through isLocked() as well.
            $php = $outcome === FollowUpChild::ACTIVE_OUTCOME
                ? null
                : FollowUpChild::classifyReturn($outcome, $before, $after);

            $this->assertSame($expected, $php, "PHP {$label}");
            $this->assertSame($expected, $sql, "SQL {$label}");
            $this->assertSame(
                FollowUpChild::admissionCategoryOf($expected),
                $this->expectedCategory($expected),
                "MEAL column {$label}",
            );
        }
    }

    public function test_meal_counts_every_combination_in_its_one_column(): void
    {
        $expected = ['new' => 0, 'relapse' => 0, 'readmission' => 0];
        $n = 0;

        foreach (FollowUpChild::CLOSING_OUTCOMES as $outcome) {
            foreach (self::ADMITTED as $before) {
                // MEAL counts a return by its own programme, so only SAM/MAM
                // returns appear in it at all.
                foreach (['SAM', 'MAM'] as $after) {
                    $idNumber = (string) (462000000 + $n++);

                    $previous = $this->episode($idNumber, [
                        'admitted_with' => $before, 'admission_date' => '2026-01-05',
                        'discharge_date' => '2026-02-01', 'discharge_outcome' => $outcome,
                    ]);
                    $this->episode($idNumber, [
                        'admitted_with' => $after, 'admission_date' => '2026-03-01',
                        'previous_follow_up_child_id' => $previous->getKey(),
                    ]);

                    $expected[$this->expectedCategory($this->expected($outcome, $before, $after))]++;
                }
            }
        }

        $totals = app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(2026, 3, 3), null)[MealReportLayout::SHEET_CMAM]['totals'];

        foreach ($expected as $category => $count) {
            $this->assertSame($count, $this->admissions($totals, $category), "MEAL {$category}");
        }

        $this->assertSame(array_sum($expected), $this->admissions($totals, null), 'Each return counted exactly once.');
    }

    /**
     * The finalized rules, written out independently of the model.
     */
    private function expected(string $outcome, ?string $before, ?string $after): ?string
    {
        $malnourished = static fn (?string $fi): bool => in_array($fi, ['SAM', 'MAM'], true);

        return match (true) {
            $outcome === 'defaulted' => FollowUpChild::READMISSION_AFTER_DEFAULTED,
            in_array($outcome, ['discharge_to_opt', 'discharge_to_other', 'referred_medical_inpt'], true) => FollowUpChild::READMISSION_AFTER_OTHER,
            $outcome === 'cured' && $malnourished($before) && $malnourished($after) => FollowUpChild::READMISSION_AFTER_RELAPSE,
            // non_responded, died, a cure that was not SAM/MAM or not back at
            // SAM/MAM, an open episode: New.
            default => null,
        };
    }

    private function expectedCategory(?string $classification): string
    {
        return match ($classification) {
            FollowUpChild::READMISSION_AFTER_RELAPSE => 'relapse',
            FollowUpChild::READMISSION_AFTER_DEFAULTED, FollowUpChild::READMISSION_AFTER_OTHER => 'readmission',
            default => 'new',
        };
    }

    private function admissions(array $totals, ?string $category): int
    {
        $sum = 0;

        foreach ($totals as $key => $value) {
            if (str_contains($key, '_adm_') && is_numeric($value)
                && ($category === null || str_contains($key, "_{$category}_"))) {
                $sum += (int) $value;
            }
        }

        return $sum;
    }

    private function episode(string $idNumber, array $attributes): FollowUpChild
    {
        return FollowUpChild::create(array_merge([
            'id_number' => $idNumber,
            'child_name' => 'Matrix child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ], $attributes));
    }
}
