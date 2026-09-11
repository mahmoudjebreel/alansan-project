<?php

namespace App\Services;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\PregnantLactatingWoman;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use App\Support\MealReport\SiteVocabulary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates the MEAL monitoring report straight out of the existing module
 * tables. Read-only: nothing here writes.
 *
 * Four rules shape how the queries are written:
 *
 *  - Counting happens in the database. Each sheet is built from a handful of
 *    GROUP BY queries; no query hydrates a model or walks every record.
 *  - Nutrition status is never re-implemented. The grouped rows keep the raw
 *    MUAC value as one of their grouping keys, and the classification is then
 *    applied by the very helpers the rest of the system uses
 *    (Child::classifyMuac, PregnantLactatingWoman::classifyMuac). Change a
 *    threshold there and this report follows automatically.
 *  - A report covers a ReportPeriod, not a single month. The period is read in
 *    one pass per source - a BETWEEN over the whole window - and the rows are
 *    bucketed to their own month afterwards, so reporting five months costs
 *    the same number of queries as reporting one.
 *  - Every sheet is filtered on its own date. Screening uses the reporting
 *    date, IYCF the counselling or session date, CMAM the admission date for
 *    admissions and the discharge date for discharges (and for the length of
 *    stay, which is reported in the month the case closed).
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

        foreach (MealReportLayout::columns(MealReportLayout::SHEET_CMAM) as $key) {
            // discharge_outcome has no value that means "unknown". (Non
            // Responded is its own outcome now and fills _dis_no_response_.)
            if (str_contains($key, '_dis_unknown_')) {
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
                // group_sessions.category has no grandmother / reproductive-age value.
                'part_grandmother_new', 'part_grandmother_fu', 'part_wra_new', 'part_wra_fu',
            ],
            MealReportLayout::SHEET_CMAM => array_values(array_unique($cmam)),
        ];
    }

    /**
     * Build every sheet for one month and site.
     *
     * Kept as the single-month spelling of buildPeriod(): a one month report
     * is simply a period whose two ends are the same month.
     *
     * @return array<string, array{rows: array, totals: array, monthStarts: array, review: array}>
     */
    public function build(int $year, int $month, ?string $site): array
    {
        return $this->buildPeriod(ReportPeriod::month($year, $month), $site);
    }

    /**
     * Build every sheet for a run of consecutive months and one site.
     *
     * Each month is counted strictly on its own - a record only ever lands in
     * the bucket of the month its own date falls in - and the months are then
     * laid out in calendar order inside the same sheet, which is exactly what
     * the template's MONTH column is for.
     *
     * @return array<string, array{rows: array, totals: array, monthStarts: array, review: array}>
     */
    public function buildPeriod(ReportPeriod $period, ?string $site): array
    {
        [$screening, $review] = $this->screening($period, $site);

        return [
            MealReportLayout::SHEET_SCREENING => $this->finalise(
                MealReportLayout::SHEET_SCREENING, $period, $site, $screening, $review,
            ),
            MealReportLayout::SHEET_IYCF => $this->finalise(
                MealReportLayout::SHEET_IYCF, $period, $site, $this->iycf($period, $site),
            ),
            MealReportLayout::SHEET_CMAM => $this->finalise(
                MealReportLayout::SHEET_CMAM, $period, $site, $this->cmam($period, $site),
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Sheet 1 - Screening Children and PBW
    // -----------------------------------------------------------------

    /**
     * @return array{0: array<int, array<int, array<string, int>>>, 1: array<string, int>}
     */
    private function screening(ReportPeriod $period, ?string $site): array
    {
        $buckets = [];

        // A screening that cannot be placed in a template cell is counted here
        // instead of being dropped without trace. Inventing a status for an
        // unmeasured child would file them under Normal, which is precisely
        // the reading the programme must never make.
        $review = ['children_missing_muac' => 0, 'children_missing_age' => 0, 'women_missing_muac' => 0];

        $children = Child::query()
            ->whereBetween('date_of_reporting', $period->dateRange())
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('type_of_site', SiteVocabulary::typeOfSite($site)))
            ->selectRaw('date_of_reporting, visit_type, sex, has_oedema, is_pwd, muac_mm, date_of_birth, age_months, COUNT(*) as aggregate_count')
            ->groupBy('date_of_reporting', 'visit_type', 'sex', 'has_oedema', 'is_pwd', 'muac_mm', 'date_of_birth', 'age_months')
            ->get();

        foreach ($children as $row) {
            $on = Carbon::parse($row->date_of_reporting);
            $count = (int) $row->aggregate_count;
            $sex = $row->sex === 'female' ? 'female' : 'male';

            // Screening visit type, straight from the record. It says whether
            // this was the child's first screening or a later one, and has
            // nothing to do with any CMAM visit number.
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';

            $ageMonths = $this->monthsBetween($row->date_of_birth, $row->date_of_reporting) ?? $row->age_months;

            // Nutrition status comes from the shared classifier, never from a
            // copy of the thresholds. Oedema then outranks the MUAC reading:
            // an oedematous child is counted in the Oedema column, never again
            // under Normal/MAM/SAM.
            $muacStatus = $this->slugStatus(Child::classifyMuac($row->muac_mm));
            $status = $row->has_oedema ? 'oedema' : $muacStatus;

            if ($ageMonths === null) {
                $review['children_missing_age'] += $count;

                continue;
            }

            $band = $this->childBand((int) $ageMonths);

            // Outside 6-59 months the child is not in this sheet's scope at
            // all, which is not a data problem and so is not flagged.
            if ($band === null) {
                continue;
            }

            // No oedema and no usable MUAC: the child was screened but not
            // classified, so they belong in no status column at all.
            if ($status === null) {
                $review['children_missing_muac'] += $count;

                continue;
            }

            // Visit type x age band x nutrition status x sex, all four taken
            // from the same record.
            $this->add($buckets, $on, "{$band}_{$visit}_{$status}_{$sex}", $count);

            // The PWD block spans the whole 6-59 range and has no Oedema
            // column. Bilateral pitting oedema is severe acute malnutrition
            // whatever the tape reads, so an oedematous child is counted here
            // under SAM rather than dropped out of the block altogether.
            if ($row->is_pwd) {
                $pwd = $row->has_oedema ? 'sam' : $muacStatus;

                if ($pwd !== null) {
                    $this->add($buckets, $on, "pwd_{$pwd}_{$sex}", $count);
                }
            }
        }

        $women = PregnantLactatingWoman::query()
            ->whereBetween('date_of_reporting', $period->dateRange())
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('type_of_site', SiteVocabulary::typeOfSite($site)))
            ->selectRaw('date_of_reporting, visit_type, status_type, is_pwd, muac_mm, date_of_birth, age_years, COUNT(*) as aggregate_count')
            ->groupBy('date_of_reporting', 'visit_type', 'status_type', 'is_pwd', 'muac_mm', 'date_of_birth', 'age_years')
            ->get();

        foreach ($women as $row) {
            $on = Carbon::parse($row->date_of_reporting);
            $count = (int) $row->aggregate_count;

            // <230mm is the same threshold the template draws at 23cm.
            $classification = PregnantLactatingWoman::classifyMuac($row->muac_mm);

            if ($classification === null) {
                $review['women_missing_muac'] += $count;

                continue;
            }

            $wasting = $classification === 'Normal' ? 'not_wasted' : 'wasted';
            $group = $row->status_type === 'pregnant' ? 'pw' : 'bf';
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';
            $years = $this->yearsBetween($row->date_of_birth, $row->date_of_reporting) ?? $row->age_years;

            if ($years === null) {
                continue;
            }

            $this->add($buckets, $on, "{$group}_{$visit}_{$wasting}_{$this->womanBand((int) $years)}", $count);

            if ($row->is_pwd) {
                $this->add($buckets, $on, $classification === 'Normal' ? 'pbw_pwd_normal' : 'pbw_pwd_mam', $count);
            }
        }

        return [$buckets, $review];
    }

    // -----------------------------------------------------------------
    // Sheet 2 - IYCF Group & Individual
    // -----------------------------------------------------------------

    /**
     * IYCF activity, and only that: every figure comes from the counselling
     * and group session modules on their own activity dates. No screening
     * record ever reaches this sheet.
     *
     * @return array<int, array<int, array<string, int>>>
     */
    private function iycf(ReportPeriod $period, ?string $site): array
    {
        $buckets = [];

        $counselling = IndividualCounseling::query()
            ->whereBetween('date', $period->dateRange())
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)))
            ->selectRaw('date, mother_visit_type, child_visit_type, gender, p_l, consultation, status, outcome, mother_dob, mother_age_years, child_dob, age_months, COUNT(*) as aggregate_count')
            ->groupBy('date', 'mother_visit_type', 'child_visit_type', 'gender', 'p_l', 'consultation', 'status', 'outcome', 'mother_dob', 'mother_age_years', 'child_dob', 'age_months')
            ->get();

        foreach ($counselling as $row) {
            $on = Carbon::parse($row->date);
            $count = (int) $row->aggregate_count;
            $motherVisit = $row->mother_visit_type === 'follow_up' ? 'fu' : 'new';
            $childVisit = $row->child_visit_type === 'follow_up' ? 'fu' : 'new';
            $childMonths = $this->monthsBetween($row->child_dob, $row->date) ?? $row->age_months;
            $motherYears = $this->yearsBetween($row->mother_dob, $row->date)
                ?? (is_numeric($row->mother_age_years) ? (int) $row->mother_age_years : null);

            // Caregivers of a 0-23 month old, by the mother's age bracket.
            if ($motherYears !== null && $childMonths !== null && $childMonths <= 23) {
                $this->add($buckets, $on, "cg_{$motherVisit}_{$this->womanBand($motherYears)}", $count);
            }

            // "Pregnant women (only)" - p_l 'P', not the combined 'P+L'.
            if ($motherYears !== null && $row->p_l === 'P') {
                $this->add($buckets, $on, "pw_{$motherVisit}_{$this->womanBand($motherYears)}", $count);
            }

            $help = $this->helpType($row->consultation);

            if ($help !== null) {
                $this->add($buckets, $on, "help_{$help}_{$motherVisit}", $count);
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
                    $this->add($buckets, $on, "disch_{$help}_{$outcome}", $count);
                }

                if (in_array($row->p_l, ['P', 'L', 'P+L'], true)) {
                    $this->add($buckets, $on, 'plw_discharged', $count);
                }
            }

            // Infants and children supported, by the child's own visit type.
            if ($childMonths !== null) {
                $sex = $row->gender === 'F' ? 'female' : 'male';

                if ($childMonths <= 5) {
                    $this->add($buckets, $on, "ch0_5_{$childVisit}_{$sex}", $count);
                } elseif ($childMonths <= 23) {
                    $this->add($buckets, $on, "ch6_23_{$childVisit}_{$sex}", $count);
                }
            }
        }

        $sessions = GroupSession::query()
            ->whereBetween('session_date', $period->dateRange())
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)))
            ->selectRaw('session_date, category, visit_type, is_pwd, COUNT(*) as aggregate_count')
            ->groupBy('session_date', 'category', 'visit_type', 'is_pwd')
            ->get();

        foreach ($sessions as $row) {
            $on = Carbon::parse($row->session_date);
            $count = (int) $row->aggregate_count;
            $visit = $row->visit_type === 'follow_up' ? 'fu' : 'new';

            $category = match ($row->category) {
                'pregnant' => 'pregnant',
                'caregiver_child_under_6_months' => 'cg_infant',
                'caregiver_child_6_23_months' => 'cg_child',
                default => null,
            };

            if ($category !== null) {
                $this->add($buckets, $on, "part_{$category}_{$visit}", $count);
            }

            if ($row->is_pwd) {
                $this->add($buckets, $on, 'participants_disabled', $count);
            }

            $this->add($buckets, $on, 'participants_total', $count);
        }

        // A "session conducted" is a distinct session group on a given day.
        $conducted = GroupSession::query()
            ->whereBetween('session_date', $period->dateRange())
            ->when($this->siteChosen($site), fn (Builder $q) => $q->where('shelter_name', SiteVocabulary::shelterName($site)))
            ->selectRaw('session_date, COUNT(DISTINCT session_group_number) as aggregate_count')
            ->groupBy('session_date')
            ->get();

        foreach ($conducted as $row) {
            $this->add($buckets, Carbon::parse($row->session_date), 'group_sessions', (int) $row->aggregate_count);
        }

        return $buckets;
    }

    // -----------------------------------------------------------------
    // Sheet 3 - CMAM
    // -----------------------------------------------------------------

    /**
     * The CMAM treatment journey, and only that: admission, then closure.
     * Every figure comes from follow_up_children, one row per episode, so an
     * episode is counted once as an admission (on its admission date) and at
     * most once as a discharge (on its discharge date). A readmission is its
     * own row and therefore its own admission; the closed episode it follows
     * keeps its own historical admission and discharge.
     *
     * A repeated screening in the Children module is a screening follow-up,
     * never a CMAM event, so nothing on this sheet is derived from how often a
     * child ID appears there - and a Normal screening is never a recovery.
     *
     * @return array<int, array<int, array<string, int|float|array{days: int, cases: int}>>>
     */
    private function cmam(ReportPeriod $period, ?string $site): array
    {
        $buckets = [];
        $table = (new FollowUpChild)->getTable();
        $admissionKind = $this->cmamAdmissionKindExpression($table);

        // Admissions fall in the month they were admitted in.
        //
        // follow_up_children carries no oedema flag of its own; the screening
        // visit an episode was raised from (source_child_visit_id) does. It is
        // joined for that one column and nothing else, so an episode with no
        // linked screening is simply an admission without oedema.
        $admissions = $this->followUpQuery($site)
            ->leftJoin((new Child)->getTable() . ' as screening', 'screening.id', '=', "{$table}.source_child_visit_id")
            ->whereBetween("{$table}.admission_date", $period->dateRange())
            ->selectRaw("{$table}.admission_date, {$table}.admitted_with, {$table}.sex, {$table}.dob, screening.has_oedema, {$admissionKind} as admission_kind, COUNT(*) as aggregate_count")
            ->groupBy(["{$table}.admission_date", "{$table}.admitted_with", "{$table}.sex", "{$table}.dob", 'screening.has_oedema', 'admission_kind'])
            ->get();

        foreach ($admissions as $row) {
            $band = $this->cmamBand($this->monthsBetween($row->dob, $row->admission_date));
            $programme = $this->programme($row->admitted_with);

            if ($band === null || $programme === null) {
                continue;
            }

            // New, relapse or readmission - see cmamAdmissionKindExpression().
            $kind = $row->admission_kind;

            // The template keeps SAM with oedema apart from the other SAM
            // admissions (its discharge block is the one marked "including
            // oedema"), so an oedematous SAM admission is counted there and
            // not again under plain SAM. The classification itself is the
            // stored admitted_with: a MAM admission stays MAM whatever the
            // screening's oedema flag says.
            $prefix = $programme === 'sam' && (bool) $row->has_oedema ? 'sam_oedema' : $programme;

            $this->add(
                $buckets,
                Carbon::parse($row->admission_date),
                "{$prefix}_adm_{$band}_{$kind}_{$this->cmamSex($row->sex)}",
                (int) $row->aggregate_count,
            );
        }

        // Discharges fall in the month they were discharged in, which is a
        // different window from the admissions above.
        $discharges = $this->followUpQuery($site)
            ->whereBetween('discharge_date', $period->dateRange())
            ->selectRaw('discharge_date, admission_date, discharge_outcome, admitted_with, sex, dob, COUNT(*) as aggregate_count')
            ->groupBy('discharge_date', 'admission_date', 'discharge_outcome', 'admitted_with', 'sex', 'dob')
            ->get();

        foreach ($discharges as $row) {
            $dischargedOn = Carbon::parse($row->discharge_date);
            $programme = $this->programme($row->admitted_with);

            // The age band is the child's age on admission, so a case stays in
            // the band it was admitted into however long the treatment ran.
            $band = $this->cmamBand($this->monthsBetween($row->dob, $row->admission_date));

            if ($programme === null || $band === null) {
                continue;
            }

            $count = (int) $row->aggregate_count;
            $sex = $this->cmamSex($row->sex);

            // Only a recorded closure is a discharge, and 'cured' is the only
            // thing that counts as recovered.
            $outcome = match ($row->discharge_outcome) {
                'cured' => 'recovered',
                'defaulted' => 'defaulted',
                'died' => 'died',
                'discharge_to_opt' => 'referred_medical',
                'discharge_to_other' => 'other',
                // The two outcomes that used to be stored as
                // 'discharge_to_other'; each lands in the template column
                // that was already waiting for it.
                'non_responded' => 'no_response',
                'referred_medical_inpt' => 'referred_medical',
                // 'under_follow_up' is an open case, not a discharge at all.
                default => null,
            };

            // Not a discharge at all (still under follow-up, or no outcome
            // recorded), so neither a discharge nor a length of stay.
            if ($outcome === null) {
                continue;
            }

            $this->add($buckets, $dischargedOn, "{$programme}_dis_{$outcome}_{$band}_{$sex}", $count);

            // A SAM case referred out of the programme: to inpatient care
            // for a medical reason, or to another OTP.
            if ($programme === 'sam' && in_array($row->discharge_outcome, ['referred_medical_inpt', 'discharge_to_opt'], true)) {
                $this->add($buckets, $dischargedOn, "sam_referred_{$band}_{$sex}", $count);
            }

            // Length of stay: admission date to discharge date, in days. The
            // day total and the case count are both kept, so every average
            // (the day's, the month's, the period's) is taken over the cases
            // themselves rather than over other averages.
            if ($row->admission_date !== null) {
                $length = (int) Carbon::parse($row->admission_date)->diffInDays($dischargedOn, absolute: true);
                $this->addStay($buckets, $dischargedOn, "{$programme}_los_{$band}_{$sex}", $length * $count, $count);
            }
        }

        return $buckets;
    }

    /**
     * How an admission is reported: 'new', 'relapse' or 'readmission'.
     *
     * Readmission is what the episode says it is: admission_type is written
     * by the readmission workflow and nowhere else. Of the rest, the child's
     * first episode on file is a new admission; an episode opened after an
     * earlier one - the child came back after a closed episode whose outcome
     * did not allow a readmission, typically a cure - is a relapse. This is
     * the same reading the Children module's relapse rule makes when it
     * raises the admission: a known child deteriorating is a relapse, and a
     * new admission. Trashed episodes are not part of the history, exactly
     * as everywhere else in the system.
     *
     * Written as one SQL expression so the admissions stay a single grouped
     * query, whatever the length of the period.
     */
    private function cmamAdmissionKindExpression(string $table): string
    {
        $readmission = FollowUpChild::ADMISSION_READMISSION;

        return "CASE
            WHEN {$table}.admission_type = '{$readmission}' THEN 'readmission'
            WHEN EXISTS (
                SELECT 1 FROM {$table} AS earlier
                WHERE earlier.id_number = {$table}.id_number
                  AND earlier.deleted_at IS NULL
                  AND earlier.id <> {$table}.id
                  AND (
                      earlier.admission_date < {$table}.admission_date
                      OR (earlier.admission_date = {$table}.admission_date AND earlier.id < {$table}.id)
                  )
            ) THEN 'relapse'
            ELSE 'new'
        END";
    }

    /**
     * Accumulate a length-of-stay bucket: total days over total cases.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $buckets
     */
    private function addStay(array &$buckets, Carbon $on, string $key, int $days, int $cases): void
    {
        $bucket = $buckets[$on->month][$on->day][$key] ?? ['days' => 0, 'cases' => 0];

        $buckets[$on->month][$on->day][$key] = [
            'days' => $bucket['days'] + $days,
            'cases' => $bucket['cases'] + $cases,
        ];
    }

    // -----------------------------------------------------------------
    // Shared query pieces
    // -----------------------------------------------------------------

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
     * Turn the month => day => key => count map into ordered rows, one totals
     * row per month, and a totals row for the whole period.
     *
     * The months are walked in calendar order - never alphabetical - and every
     * month in the period gets rows, including one with no data at all: a
     * monitoring report is read as a sequence, and a month that quietly
     * disappeared would read as a month nobody was meant to look at.
     *
     * Each month's total is summed from that month's rows and nothing else,
     * so "Total August" is August alone even when the report runs to October.
     * The period total is the sum of the monthly totals.
     *
     * An average column (length of stay) arrives as a day total and a case
     * count rather than a number, and every row - the day's, the month's and
     * the period's - shows the days divided by the cases of that row alone. A
     * month's average is therefore taken over the cases discharged in that
     * month, not over the averages of its days.
     *
     * @param  array<int, array<int, array<string, int|float|array{days: int, cases: int}>>>  $buckets
     * @param  array<string, int>  $review
     * @return array{rows: array, totals: array, monthTotals: array<int, array>, monthStarts: array<int>, review: array<string, int>}
     */
    private function finalise(string $sheet, ReportPeriod $period, ?string $site, array $buckets, array $review = []): array
    {
        $columns = MealReportLayout::columns($sheet);
        $unsupported = array_flip(self::unsupportedColumns()[$sheet]);
        $averages = array_flip(MealReportLayout::averageColumns($sheet));

        $rows = [];
        $monthStarts = [];
        $monthTotals = [];
        $totals = array_fill_keys($columns, 0);
        $stays = [];

        foreach ($period->months() as $month) {
            $monthLabel = $period->monthLabel($month);
            $daysOfMonth = $buckets[$month] ?? [];
            ksort($daysOfMonth);

            // Index of this month's first row, so the exporter can rule a line
            // between one month and the next.
            $monthStarts[] = count($rows);

            // This month's own running total, started afresh for every month.
            $monthTotal = array_fill_keys($columns, 0);
            $monthStays = [];

            // A month with nothing in it still gets a row, all zero, so the
            // sequence of months in the file stays unbroken.
            if ($daysOfMonth === []) {
                $daysOfMonth = ['' => []];
            }

            foreach ($daysOfMonth as $day => $values) {
                $row = [];

                foreach ($columns as $key) {
                    if (isset($averages[$key])) {
                        $stay = $values[$key] ?? ['days' => 0, 'cases' => 0];
                        $row[$key] = $this->average($stay);
                        $this->accumulateStay($monthStays, $key, $stay);
                        $this->accumulateStay($stays, $key, $stay);

                        continue;
                    }

                    $row[$key] = match (true) {
                        $key === 'mba' => SiteVocabulary::label($site),
                        $key === 'month' => $monthLabel,
                        $key === 'day' => $day === '' ? '' : $day,
                        isset($unsupported[$key]) => null,
                        default => $values[$key] ?? 0,
                    };

                    if (isset($unsupported[$key]) || in_array($key, ['mba', 'month', 'day'], true)) {
                        continue;
                    }

                    $monthTotal[$key] += $row[$key];
                    $totals[$key] += $row[$key];
                }

                $rows[] = $row;
            }

            // "Total August": the sum of August's rows only.
            $monthTotal['mba'] = 'Total ' . $monthLabel;
            $monthTotal['month'] = $monthLabel;
            $monthTotal['day'] = '';

            foreach (array_keys($unsupported) as $key) {
                $monthTotal[$key] = null;
            }

            // The month's average over the month's own cases.
            foreach (array_keys($averages) as $key) {
                $monthTotal[$key] = $this->average($monthStays[$key] ?? ['days' => 0, 'cases' => 0]);
            }

            $monthTotals[$month] = $monthTotal;
        }

        // The Total row spans the selected months and nothing else: every
        // query above is already narrowed to the period.
        $totals['mba'] = 'Total';
        $totals['month'] = '';
        $totals['day'] = '';

        foreach (array_keys($unsupported) as $key) {
            $totals[$key] = null;
        }

        // The period's average over every case discharged in the period.
        foreach (array_keys($averages) as $key) {
            $totals[$key] = $this->average($stays[$key] ?? ['days' => 0, 'cases' => 0]);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'monthTotals' => $monthTotals,
            'monthStarts' => $monthStarts,
            'review' => $review,
        ];
    }

    /**
     * File a count under the month and day of the date it happened on, which
     * is what keeps one month's data out of another month's rows.
     *
     * @param  array<int, array<int, array<string, int|float>>>  $buckets
     */
    private function add(array &$buckets, Carbon $on, string $key, int $count): void
    {
        $month = $on->month;
        $day = $on->day;

        $buckets[$month][$day][$key] = ($buckets[$month][$day][$key] ?? 0) + $count;
    }

    /**
     * Fold one length-of-stay bucket into a running one.
     *
     * @param  array<string, array{days: int, cases: int}>  $into
     * @param  array{days: int, cases: int}  $stay
     */
    private function accumulateStay(array &$into, string $key, array $stay): void
    {
        $into[$key] = [
            'days' => ($into[$key]['days'] ?? 0) + $stay['days'],
            'cases' => ($into[$key]['cases'] ?? 0) + $stay['cases'],
        ];
    }

    /**
     * Days over cases, to one decimal; 0 where there were no cases, which is
     * how a measured column that saw nothing has always read.
     *
     * @param  array{days: int, cases: int}  $stay
     */
    private function average(array $stay): float|int
    {
        return $stay['cases'] > 0 ? round($stay['days'] / $stay['cases'], 1) : 0;
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
}
