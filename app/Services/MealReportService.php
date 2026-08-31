<?php

namespace App\Services;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\PregnantLactatingWoman;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\SiteVocabulary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aggregates the MEAL monthly monitoring report straight out of the existing
 * module tables. Read-only: nothing here writes.
 *
 * Two rules shape how the queries are written:
 *
 *  - Counting happens in the database. Each sheet is built from a handful of
 *    GROUP BY queries; no query hydrates a model or walks every record.
 *  - Nutrition status is never re-implemented. The grouped rows keep the raw
 *    MUAC value as one of their grouping keys, and the classification is then
 *    applied by the very helpers the rest of the system uses
 *    (Child::classifyMuac, PregnantLactatingWoman::classifyMuac). Change a
 *    threshold there and this report follows automatically.
 *
 * Columns the template asks for that this system does not capture are returned
 * as null rather than 0, so a blank cell reads as "not measured" instead of
 * "measured, none found". unsupportedColumns() lists them.
 */
class MealReportService
{
    /**
     * Template columns with no source anywhere in the database.
     *
     * @return array<string, array<string>>
     */
    public static function unsupportedColumns(): array
    {
        $cmam = [];

        // No oedema flag on follow_up_children, so SAM-with-oedema admissions
        // cannot be separated out at all.
        foreach (MealReportLayout::columns(MealReportLayout::SHEET_CMAM) as $key) {
            if (str_starts_with($key, 'sam_oedema_adm_')) {
                $cmam[] = $key;
            }

            // discharge_outcome has no value for either of these.
            if (str_contains($key, '_dis_no_response_') || str_contains($key, '_dis_unknown_')) {
                $cmam[] = $key;
            }

            // Admissions are not classified as new / relapse / readmission.
            if (str_contains($key, '_adm_') && (str_contains($key, '_relapse_') || str_contains($key, '_readmission_'))) {
                $cmam[] = $key;
            }

            // Nothing records caregiver counselling against a CMAM case.
            if (str_starts_with($key, 'cg_counselled_')) {
                $cmam[] = $key;
            }
        }

        return [
            MealReportLayout::SHEET_SCREENING => [],
            MealReportLayout::SHEET_IYCF => [
                // "Wet nursing" is not one of the consultation options.
                'disch_wet_nursing_improved', 'disch_wet_nursing_not_improved', 'disch_wet_nursing_worsened',
                // Breast-milk-substitute support and violations are not recorded.
                'bms0_5_new_male', 'bms0_5_new_female', 'bms0_5_fu_male', 'bms0_5_fu_female',
                'bms_violations',
            ],
            MealReportLayout::SHEET_CMAM => array_values(array_unique($cmam)),
        ];
    }

    /**
     * Build every sheet for one month and site.
     *
     * @return array<string, array{rows: array<int, array<string, int|float|string|null>>, totals: array<string, int|float|null>}>
     */
    public function build(int $year, int $month, ?string $site): array
    {
        return [
            MealReportLayout::SHEET_SCREENING => $this->finalise(
                MealReportLayout::SHEET_SCREENING, $year, $month, $site, $this->screening($year, $month, $site),
            ),
            MealReportLayout::SHEET_IYCF => $this->finalise(
                MealReportLayout::SHEET_IYCF, $year, $month, $site, $this->iycf($year, $month, $site),
            ),
            MealReportLayout::SHEET_CMAM => $this->finalise(
                MealReportLayout::SHEET_CMAM, $year, $month, $site, $this->cmam($year, $month, $site),
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Sheet 1 - Screening Children and PBW
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, int>>
     */
    private function screening(int $year, int $month, ?string $site): array
    {
        $days = [];

        $children = Child::query()
            ->whereYear('date_of_reporting', $year)
            ->whereMonth('date_of_reporting', $month)
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('type_of_site', SiteVocabulary::typeOfSite($site)))
            ->selectRaw('date_of_reporting, visit_type, sex, has_oedema, is_pwd, muac_mm, date_of_birth, age_months, COUNT(*) as aggregate_count')
            ->groupBy('date_of_reporting', 'visit_type', 'sex', 'has_oedema', 'is_pwd', 'muac_mm', 'date_of_birth', 'age_months')
            ->get();

        foreach ($children as $row) {
            $day = Carbon::parse($row->date_of_reporting)->day;
            $count = (int) $row->aggregate_count;
            $sex = $row->sex === 'female' ? 'female' : 'male';
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';
            $ageMonths = $this->monthsBetween($row->date_of_birth, $row->date_of_reporting) ?? $row->age_months;

            // Nutrition status comes from the shared classifier, never from a
            // copy of the thresholds. Oedema then outranks the MUAC reading:
            // an oedematous child is counted in the Oedema column, never again
            // under Normal/MAM/SAM.
            $muacStatus = $this->slugStatus(Child::classifyMuac($row->muac_mm));
            $status = $row->has_oedema ? 'oedema' : $muacStatus;

            if ($status === null || $ageMonths === null) {
                continue;
            }

            $band = $this->childBand($ageMonths);

            if ($band === null) {
                continue;
            }

            // Visit type x age band x nutrition status x sex, all four taken
            // from the same record.
            $this->add($days, $day, "{$band}_{$visit}_{$status}_{$sex}", $count);

            // The PWD block spans the whole 6-59 range and has no Oedema
            // column. Bilateral pitting oedema is severe acute malnutrition
            // whatever the tape reads, so an oedematous child is counted here
            // under SAM rather than dropped out of the block altogether.
            if ($row->is_pwd) {
                $this->add($days, $day, 'pwd_' . ($row->has_oedema ? 'sam' : $muacStatus) . "_{$sex}", $count);
            }
        }

        $women = PregnantLactatingWoman::query()
            ->whereYear('date_of_reporting', $year)
            ->whereMonth('date_of_reporting', $month)
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('type_of_site', SiteVocabulary::typeOfSite($site)))
            ->selectRaw('date_of_reporting, visit_type, status_type, is_pwd, muac_mm, date_of_birth, age_years, COUNT(*) as aggregate_count')
            ->groupBy('date_of_reporting', 'visit_type', 'status_type', 'is_pwd', 'muac_mm', 'date_of_birth', 'age_years')
            ->get();

        foreach ($women as $row) {
            $day = Carbon::parse($row->date_of_reporting)->day;
            $count = (int) $row->aggregate_count;

            // <230mm is the same threshold the template draws at 23cm.
            $classification = PregnantLactatingWoman::classifyMuac($row->muac_mm);

            if ($classification === null) {
                continue;
            }

            $wasting = $classification === 'Normal' ? 'not_wasted' : 'wasted';
            $group = $row->status_type === 'pregnant' ? 'pw' : 'bf';
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';
            $years = $this->yearsBetween($row->date_of_birth, $row->date_of_reporting) ?? $row->age_years;

            if ($years === null) {
                continue;
            }

            $this->add($days, $day, "{$group}_{$visit}_{$wasting}_{$this->womanBand($years)}", $count);

            if ($row->is_pwd) {
                $this->add($days, $day, $classification === 'Normal' ? 'pbw_pwd_normal' : 'pbw_pwd_mam', $count);
            }
        }

        return $days;
    }

    // -----------------------------------------------------------------
    // Sheet 2 - IYCF Group & Individual
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, int>>
     */
    private function iycf(int $year, int $month, ?string $site): array
    {
        $days = [];

        $counselling = $this->counselingQuery($year, $month, $site)
            ->selectRaw('date, mother_visit_type, child_visit_type, gender, p_l, consultation, status, outcome, mother_dob, mother_age_years, child_dob, age_months, COUNT(*) as aggregate_count')
            ->groupBy('date', 'mother_visit_type', 'child_visit_type', 'gender', 'p_l', 'consultation', 'status', 'outcome', 'mother_dob', 'mother_age_years', 'child_dob', 'age_months')
            ->get();

        foreach ($counselling as $row) {
            $day = Carbon::parse($row->date)->day;
            $count = (int) $row->aggregate_count;
            $motherVisit = $row->mother_visit_type === 'follow_up' ? 'fu' : 'new';
            $childVisit = $row->child_visit_type === 'follow_up' ? 'fu' : 'new';
            $childMonths = $this->monthsBetween($row->child_dob, $row->date) ?? $row->age_months;
            $motherYears = $this->yearsBetween($row->mother_dob, $row->date)
                ?? (is_numeric($row->mother_age_years) ? (int) $row->mother_age_years : null);

            // Caregivers of a 0-23 month old, by the mother's age bracket.
            if ($motherYears !== null && $childMonths !== null && $childMonths <= 23) {
                $this->add($days, $day, "cg_{$motherVisit}_{$this->womanBand($motherYears)}", $count);
            }

            // "Pregnant women (only)" - p_l 'P', not the combined 'P+L'.
            if ($motherYears !== null && $row->p_l === 'P') {
                $this->add($days, $day, "pw_{$motherVisit}_{$this->womanBand($motherYears)}", $count);
            }

            $help = $this->helpType($row->consultation);

            if ($help !== null) {
                $this->add($days, $day, "help_{$help}_{$motherVisit}", $count);
            }

            if ($row->status === 'discharged') {
                $outcome = match ($row->outcome) {
                    'improved' => 'improved',
                    'dont_improve' => 'not_improved',
                    // The nearest available value; the template's own wording is "Worsened".
                    'non_response' => 'worsened',
                    default => null,
                };

                if ($help !== null && $help !== 'other' && $outcome !== null) {
                    $this->add($days, $day, "disch_{$help}_{$outcome}", $count);
                }

                if (in_array($row->p_l, ['P', 'L', 'P+L'], true)) {
                    $this->add($days, $day, 'plw_discharged', $count);
                }
            }

            // Infants and children supported, by the child's own visit type.
            if ($childMonths !== null) {
                $sex = $row->gender === 'F' ? 'female' : 'male';

                if ($childMonths <= 5) {
                    $this->add($days, $day, "ch0_5_{$childVisit}_{$sex}", $count);
                } elseif ($childMonths <= 23) {
                    $this->add($days, $day, "ch6_23_{$childVisit}_{$sex}", $count);
                }
            }
        }

        $sessions = GroupSession::query()
            ->whereYear('session_date', $year)
            ->whereMonth('session_date', $month)
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)))
            ->selectRaw('session_date, category, visit_type, is_pwd, COUNT(*) as aggregate_count')
            ->groupBy('session_date', 'category', 'visit_type', 'is_pwd')
            ->get();

        foreach ($sessions as $row) {
            $day = Carbon::parse($row->session_date)->day;
            $count = (int) $row->aggregate_count;
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';

            // Grandmothers and women of reproductive age became selectable
            // categories when the column was widened; the report kept treating
            // them as "not captured", so those participants only ever reached
            // the total and their own four columns stayed blank.
            $category = match ($row->category) {
                'pregnant' => 'pregnant',
                'caregiver_child_under_6_months' => 'cg_infant',
                'caregiver_child_6_23_months' => 'cg_child',
                'grandmothers' => 'grandmother',
                'reproductive_age' => 'wra',
                default => null,
            };

            if ($category !== null) {
                $this->add($days, $day, "part_{$category}_{$visit}", $count);
            }

            if ($row->is_pwd) {
                $this->add($days, $day, 'participants_disabled', $count);
            }

            $this->add($days, $day, 'participants_total', $count);
        }

        // A "session conducted" is a distinct session group on a given day.
        $conducted = GroupSession::query()
            ->whereYear('session_date', $year)
            ->whereMonth('session_date', $month)
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)))
            ->selectRaw('session_date, COUNT(DISTINCT session_group_number) as aggregate_count')
            ->groupBy('session_date')
            ->get();

        foreach ($conducted as $row) {
            $this->add($days, Carbon::parse($row->session_date)->day, 'group_sessions', (int) $row->aggregate_count);
        }

        return $days;
    }

    // -----------------------------------------------------------------
    // Sheet 3 - CMAM
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, int|float>>
     */
    private function cmam(int $year, int $month, ?string $site): array
    {
        $days = [];

        $admissions = $this->followUpQuery($site)
            ->whereYear('admission_date', $year)
            ->whereMonth('admission_date', $month)
            ->selectRaw('admission_date, admitted_with, sex, dob, COUNT(*) as aggregate_count')
            ->groupBy('admission_date', 'admitted_with', 'sex', 'dob')
            ->get();

        foreach ($admissions as $row) {
            $band = $this->cmamBand($this->monthsBetween($row->dob, $row->admission_date));
            $programme = $this->programme($row->admitted_with);

            if ($band === null || $programme === null) {
                continue;
            }

            // Every admission is counted as "New": nothing distinguishes a
            // relapse or a readmission - see unsupportedColumns().
            $this->add(
                $days,
                Carbon::parse($row->admission_date)->day,
                "{$programme}_adm_{$band}_new_{$this->cmamSex($row->sex)}",
                (int) $row->aggregate_count,
            );
        }

        // discharge_date is a free-text varchar, so it is matched as an ISO
        // prefix rather than compared as a date.
        $prefix = sprintf('%04d-%02d', $year, $month);

        $discharges = $this->followUpQuery($site)
            ->where('discharge_date', 'like', $prefix . '%')
            ->selectRaw('discharge_date, admission_date, discharge_outcome, admitted_with, sex, dob, COUNT(*) as aggregate_count')
            ->groupBy('discharge_date', 'admission_date', 'discharge_outcome', 'admitted_with', 'sex', 'dob')
            ->get();

        $stays = [];

        foreach ($discharges as $row) {
            $dischargedOn = $this->parseLooseDate($row->discharge_date);
            $programme = $this->programme($row->admitted_with);
            $band = $this->cmamBand($this->monthsBetween($row->dob, $row->admission_date));

            if ($dischargedOn === null || $programme === null || $band === null) {
                continue;
            }

            $day = $dischargedOn->day;
            $count = (int) $row->aggregate_count;
            $sex = $this->cmamSex($row->sex);

            $outcome = match ($row->discharge_outcome) {
                'cured' => 'recovered',
                'defaulted' => 'defaulted',
                'died' => 'died',
                'discharge_to_opt' => 'referred_medical',
                'discharge_to_other' => 'other',
                // 'under_follow_up' is not a discharge at all.
                default => null,
            };

            if ($outcome !== null) {
                $this->add($days, $day, "{$programme}_dis_{$outcome}_{$band}_{$sex}", $count);
            }

            if ($programme === 'sam' && $row->discharge_outcome === 'discharge_to_opt') {
                $this->add($days, $day, "sam_referred_{$band}_{$sex}", $count);
            }

            if ($row->admission_date !== null) {
                $length = Carbon::parse($row->admission_date)->diffInDays($dischargedOn, absolute: true);
                $key = "{$programme}_los_{$band}_{$sex}";
                $stays[$day][$key][] = ['days' => $length, 'weight' => $count];
            }
        }

        // Length of stay is an average, so it is accumulated separately.
        foreach ($stays as $day => $keys) {
            foreach ($keys as $key => $entries) {
                $weight = array_sum(array_column($entries, 'weight'));
                $total = array_sum(array_map(fn (array $e): float => $e['days'] * $e['weight'], $entries));
                $days[$day][$key] = $weight > 0 ? round($total / $weight, 1) : null;
            }
        }

        return $days;
    }

    // -----------------------------------------------------------------
    // Shared query pieces
    // -----------------------------------------------------------------

    private function counselingQuery(int $year, int $month, ?string $site): Builder
    {
        return IndividualCounseling::query()
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)));
    }

    /**
     * follow_up_children.shelter_name is free text, so the site filter has to
     * pattern-match rather than compare.
     */
    private function followUpQuery(?string $site): Builder
    {
        return FollowUpChild::query()
            ->when($this->siteChosen($site), function (Builder $query) use ($site): void {
                $query->where(function (Builder $inner) use ($site): void {
                    foreach (SiteVocabulary::freeTextPatterns($site) as $pattern) {
                        $inner->orWhere('shelter_name', 'like', $pattern);
                    }
                });
            });
    }

    private function siteChosen(?string $site): bool
    {
        return $site !== null && $site !== SiteVocabulary::ALL;
    }

    // -----------------------------------------------------------------
    // Shaping
    // -----------------------------------------------------------------

    /**
     * Turn the day => key => count map into ordered rows plus a totals row.
     *
     * @param  array<int, array<string, int|float>>  $days
     * @return array{rows: array<int, array<string, int|float|string|null>>, totals: array<string, int|float|null>}
     */
    private function finalise(string $sheet, int $year, int $month, ?string $site, array $days): array
    {
        $columns = MealReportLayout::columns($sheet);
        $unsupported = array_flip(self::unsupportedColumns()[$sheet]);
        $averages = array_flip(MealReportLayout::averageColumns($sheet));
        $monthLabel = Carbon::create($year, $month, 1)->format('F');

        ksort($days);

        $rows = [];
        $totals = array_fill_keys($columns, 0);
        $averageBuckets = [];

        foreach ($days as $day => $values) {
            $row = [];

            foreach ($columns as $key) {
                $row[$key] = match (true) {
                    $key === 'mba' => SiteVocabulary::label($site),
                    $key === 'month' => $monthLabel,
                    $key === 'day' => $day,
                    isset($unsupported[$key]) => null,
                    default => $values[$key] ?? 0,
                };

                if (isset($unsupported[$key]) || in_array($key, ['mba', 'month', 'day'], true)) {
                    continue;
                }

                if (isset($averages[$key])) {
                    if ($row[$key] !== null && $row[$key] > 0) {
                        $averageBuckets[$key][] = $row[$key];
                    }

                    continue;
                }

                $totals[$key] += $row[$key];
            }

            $rows[] = $row;
        }

        $totals['mba'] = 'Total';
        $totals['month'] = '';
        $totals['day'] = '';

        foreach (array_keys($unsupported) as $key) {
            $totals[$key] = null;
        }

        // Averaging an average: the totals row shows the mean of the daily means.
        foreach (array_keys($averages) as $key) {
            $bucket = $averageBuckets[$key] ?? [];
            $totals[$key] = $bucket === [] ? 0 : round(array_sum($bucket) / count($bucket), 1);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<int, array<string, int|float>>  $days
     */
    private function add(array &$days, int $day, string $key, int $count): void
    {
        $days[$day][$key] = ($days[$day][$key] ?? 0) + $count;
    }

    // -----------------------------------------------------------------
    // Small conversions
    // -----------------------------------------------------------------

    /** Age in months on the date the record was captured, not today. */
    private function monthsBetween(mixed $dob, mixed $reference): ?int
    {
        if (blank($dob) || blank($reference)) {
            return null;
        }

        $dob = Carbon::parse($dob);
        $reference = Carbon::parse($reference);

        return $reference->lt($dob) ? null : (int) $dob->diffInMonths($reference);
    }

    private function yearsBetween(mixed $dob, mixed $reference): ?int
    {
        if (blank($dob) || blank($reference)) {
            return null;
        }

        $dob = Carbon::parse($dob);
        $reference = Carbon::parse($reference);

        return $reference->lt($dob) ? null : (int) $dob->diffInYears($reference);
    }

    private function childBand(int $months): ?string
    {
        return match (true) {
            $months >= 6 && $months <= 23 => 'c6_23',
            $months >= 24 && $months <= 59 => 'c24_59',
            default => null,
        };
    }

    private function cmamBand(?int $months): ?string
    {
        return match (true) {
            $months === null => null,
            $months >= 6 && $months <= 23 => '6_23',
            $months >= 24 && $months <= 59 => '24_59',
            default => null,
        };
    }

    private function womanBand(int $years): string
    {
        return match (true) {
            $years < 18 => 'u18',
            $years <= 19 => 'a18_19',
            default => 'a20p',
        };
    }

    private function slugStatus(?string $classification): ?string
    {
        return match ($classification) {
            'SAM' => 'sam',
            'MAM' => 'mam',
            'Normal' => 'normal',
            default => null,
        };
    }

    private function helpType(?string $consultation): ?string
    {
        return match ($consultation) {
            'bf_support' => 'bf',
            'relactation' => 'relactation',
            'complementary_feeding' => 'cf',
            'other' => 'other',
            default => null,
        };
    }

    private function programme(?string $admittedWith): ?string
    {
        return match ($admittedWith) {
            'MAM' => 'mam',
            'SAM' => 'sam',
            default => null,
        };
    }

    private function cmamSex(?string $sex): string
    {
        return $sex === 'F' ? 'female' : 'male';
    }

    /** discharge_date is free text; only an ISO-looking value can be used. */
    private function parseLooseDate(?string $value): ?Carbon
    {
        if (blank($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse(substr($value, 0, 10));
        } catch (\Throwable) {
            return null;
        }
    }
}
