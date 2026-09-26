<?php

namespace Tests\Feature;

use App\Exports\MealReport\MealReportExport;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Services\MealReportService;
use App\Support\ChildFollowUpTransfer;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The CMAM sheet of the MEAL report, indicator by indicator.
 *
 * Every figure on the sheet is read from follow_up_children as it is stored:
 * one row per treatment episode, its admission on its admission date and its
 * closure on its discharge date. Nothing here writes to an episode other than
 * through the module's own workflow, and nothing asserts a business rule -
 * only that the report counts what the module recorded, in the right column,
 * in the right month, once.
 */
class MealCmamReportTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const JULY = 7;

    private const AUGUST = 8;

    private const SEPTEMBER = 9;

    private const SITE = 'Mossab Camp';

    private const CMAM = MealReportLayout::SHEET_CMAM;

    /** Every discharge column the template has, by the stored outcome that fills it. */
    private const OUTCOME_COLUMNS = [
        'cured' => 'recovered',
        'defaulted' => 'defaulted',
        'died' => 'died',
        'non_responded' => 'no_response',
        'referred_medical_inpt' => 'referred_medical',
        // A transfer to another OTP is a discharge to somewhere else, not a
        // medical referral.
        'discharge_to_opt' => 'other',
        'discharge_to_other' => 'other',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =================================================================
    // 1-6. Admissions: New, Relapse, Readmission - MAM and SAM
    // =================================================================

    public function test_mam_new_admission_is_counted_under_new_by_age_and_sex(): void
    {
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-05']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'F', 'age' => 30, 'admission_date' => '2026-08-05']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(1, $totals['mam_adm_24_59_new_female']);
        $this->assertSame(0, $totals['mam_adm_6_23_relapse_male']);
        $this->assertSame(0, $totals['mam_adm_6_23_readmission_male']);
        $this->assertSame(0, $totals['sam_adm_6_23_new_male']);
    }

    public function test_mam_return_after_a_cure_is_counted_under_relapse_admission(): void
    {
        // First episode, cured in June; the child is back at MAM in August
        // on a row with no link: a readmission after relapse, which the
        // report counts under Relapse admission - never under Readmission.
        $this->episode([
            'id_number' => '500000001', 'admitted_with' => 'MAM', 'sex' => 'M', 'dob' => '2025-08-05',
            'admission_date' => '2026-06-01', 'discharge_date' => '2026-06-29', 'discharge_outcome' => 'cured',
        ]);
        $this->episode([
            'id_number' => '500000001', 'admitted_with' => 'MAM', 'sex' => 'M', 'dob' => '2025-08-05',
            'admission_date' => '2026-08-05', 'admission_type' => FollowUpChild::ADMISSION_NEW,
        ]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_relapse_male']);
        $this->assertSame(0, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(0, $totals['mam_adm_6_23_readmission_male']);
    }

    public function test_a_first_episode_is_new_even_when_the_admission_type_is_blank(): void
    {
        // Rows written before admission_type existed carry NULL, which the
        // module reads as a first admission.
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-05', 'admission_type' => null]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(0, $totals['mam_adm_6_23_relapse_male']);
    }

    public function test_a_trashed_earlier_episode_does_not_make_a_relapse(): void
    {
        $earlier = $this->episode([
            'id_number' => '500000002', 'admitted_with' => 'MAM', 'sex' => 'M', 'dob' => '2025-08-05',
            'admission_date' => '2026-06-01', 'discharge_date' => '2026-06-29', 'discharge_outcome' => 'cured',
        ]);
        $earlier->delete();

        $this->episode([
            'id_number' => '500000002', 'admitted_with' => 'MAM', 'sex' => 'M', 'dob' => '2025-08-05',
            'admission_date' => '2026-08-05',
        ]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(0, $totals['mam_adm_6_23_relapse_male']);
    }

    public function test_mam_readmission_is_counted_under_readmission(): void
    {
        $previous = $this->closedEpisode('MAM', 'referred_medical_inpt', '500000003');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 118,
        ]);

        $this->assertNotNull($readmission);
        $this->assertSame('MAM', $readmission->admitted_with);

        $september = $this->sheet(self::SEPTEMBER, self::SEPTEMBER)['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['mam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['mam_adm_6_23_new_male'], 'A readmission is never a first-ever New admission.');
        $this->assertSame(0, $september['mam_adm_6_23_relapse_male']);
    }

    // =================================================================
    // Readmission classification: after defaulted and after other land in
    // Readmission, after relapse in Relapse admission, and never in New.
    // Each of these is a two-episode child: the original episode keeps its
    // own admission and discharge, the returned one is its own admission.
    // =================================================================

    public function test_a_readmission_after_defaulted_is_counted_under_readmission_not_new(): void
    {
        $previous = $this->closedEpisode('SAM', 'defaulted', '500000010');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $this->assertNotNull($readmission);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $readmission->readmissionClassification());
        $this->assertSame([1], $readmission->visits->pluck('visit_number')->all());

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][self::AUGUST];
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        // The original episode: New in August, defaulted in August.
        $this->assertSame(1, $august['sam_adm_6_23_new_male']);
        $this->assertSame(1, $august['sam_dis_defaulted_6_23_male']);

        // The returned episode: Readmission in September, and nothing else.
        $this->assertSame(1, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['sam_adm_6_23_new_male'], 'Visit 1 of a readmission is not a New admission.');
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);

        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'), 'Two episodes, two admissions, no more.');
    }

    public function test_a_readmission_after_other_is_counted_under_readmission_not_new(): void
    {
        $previous = $this->closedEpisode('MAM', 'discharge_to_other', '500000011');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 118,
        ]);

        $this->assertNotNull($readmission);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_OTHER, $readmission->readmissionClassification());

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][self::AUGUST];
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $august['mam_adm_6_23_new_male']);
        $this->assertSame(1, $august['mam_dis_other_6_23_male']);

        $this->assertSame(1, $september['mam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['mam_adm_6_23_new_male']);
        $this->assertSame(0, $september['mam_adm_6_23_relapse_male']);

        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'));
    }

    public function test_a_return_after_a_cured_sam_episode_is_counted_under_relapse_admission(): void
    {
        $this->assertRelapseCounted('SAM', 110, '500000012');
    }

    public function test_a_return_after_a_cured_mam_episode_is_counted_under_relapse_admission(): void
    {
        $this->assertRelapseCounted('MAM', 118, '500000013');
    }

    public function test_a_new_episode_after_non_responded_is_counted_under_new(): void
    {
        Carbon::setTestNow('2026-09-05');

        $this->closedEpisode('SAM', 'non_responded', '500000014');

        $episode = ChildFollowUpTransfer::refer($this->screening([
            'child_id' => '500000014', 'sex' => 'M', 'muac_mm' => 110, 'has_oedema' => false,
        ]));

        $this->assertNotNull($episode);
        $this->assertNull($episode->previous_follow_up_child_id);
        $this->assertNull($episode->readmissionClassification());

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'));
    }

    public function test_a_cured_episode_with_no_sam_mam_classification_makes_no_relapse(): void
    {
        Carbon::setTestNow('2026-09-05');

        // A Normal readmission closed as cured: admitted with neither SAM nor
        // MAM, so a later SAM screening has no SAM/MAM episode to relapse from.
        $this->episode([
            'id_number' => '500000015', 'admitted_with' => null, 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => 'cured',
        ]);

        $episode = ChildFollowUpTransfer::refer($this->screening([
            'child_id' => '500000015', 'sex' => 'M', 'muac_mm' => 110, 'has_oedema' => false,
        ]));

        $this->assertNotNull($episode);
        $this->assertNull($episode->previous_follow_up_child_id);
        $this->assertNull($episode->readmissionClassification());

        $september = $this->sheet(self::SEPTEMBER, self::SEPTEMBER)['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $september['sam_adm_6_23_readmission_male']);
    }

    public function test_an_unlinked_episode_after_a_non_responded_episode_is_new_not_relapse(): void
    {
        // A row written before the link existed, after an earlier cured
        // episode AND a later non-responded one: the latest closed episode
        // before it decides, and non-responded makes it a new admission.
        $this->episode([
            'id_number' => '500000016', 'admitted_with' => 'SAM', 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-05-01', 'discharge_date' => '2026-05-29', 'discharge_outcome' => 'cured',
        ]);
        $this->episode([
            'id_number' => '500000016', 'admitted_with' => 'SAM', 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-06-15', 'discharge_date' => '2026-07-20', 'discharge_outcome' => 'non_responded',
        ]);
        $this->episode([
            'id_number' => '500000016', 'admitted_with' => 'SAM', 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-09-05', 'admission_type' => FollowUpChild::ADMISSION_NEW,
        ]);

        $september = $this->sheet(self::SEPTEMBER, self::SEPTEMBER)['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $september['sam_adm_6_23_readmission_male']);
    }

    public function test_a_new_episode_after_died_is_counted_under_new(): void
    {
        // A death closes the history: a later row for the same ID - written
        // by hand or by an import - follows nothing and is a new admission.
        $this->closedEpisode('SAM', 'died', '500000017');

        $this->episode([
            'id_number' => '500000017', 'admitted_with' => 'SAM', 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-09-05',
        ]);

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(1, $sheet['monthTotals'][self::AUGUST]['sam_dis_died_6_23_male']);
        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'));
    }

    public function test_a_readmission_after_defaulted_raised_from_a_children_screening_is_a_readmission(): void
    {
        // The other way a readmission is opened: the child is screened SAM
        // in Children after a default, and the transfer links and types the
        // new episode itself. Same column as the readmission button.
        Carbon::setTestNow('2026-09-05');

        $previous = $this->closedEpisode('SAM', 'defaulted', '500000018');

        $readmission = ChildFollowUpTransfer::refer($this->screening([
            'child_id' => '500000018', 'sex' => 'M', 'muac_mm' => 110, 'has_oedema' => false,
        ]));

        $this->assertNotNull($readmission);
        $this->assertTrue($readmission->isReadmission());
        $this->assertSame($previous->getKey(), $readmission->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $readmission->readmissionClassification());

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'));
    }

    public function test_a_readmission_is_counted_in_the_age_band_and_sex_of_the_returned_child(): void
    {
        // A girl of 30 months at readmission: the Readmission column of the
        // 24-59 band, female - not the band or sex of any other row.
        $previous = $this->episode([
            'id_number' => '500000019', 'admitted_with' => 'MAM', 'sex' => 'F', 'dob' => '2024-03-05',
            'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => 'defaulted',
        ]);

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous->fresh(), [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 118,
        ]);

        $this->assertNotNull($readmission);

        $sheet = $this->sheet(self::SEPTEMBER, self::SEPTEMBER);
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['mam_adm_24_59_readmission_female']);
        $this->assertSame(0, $september['mam_adm_24_59_readmission_male']);
        $this->assertSame(0, $september['mam_adm_6_23_readmission_female']);
        $this->assertSame(0, $september['mam_adm_24_59_new_female']);
        $this->assertSame(0, $september['mam_adm_24_59_relapse_female']);
        $this->assertSame(0, $this->sum($september, 'sam_adm_'));
        $this->assertSame(1, $this->sum($sheet['totals'], '_adm_'));
    }

    public function test_the_three_returns_land_in_one_column_each_and_no_episode_is_counted_twice(): void
    {
        // One child of each kind, all returning in September: after a
        // default and after an other exit (Readmission), after relapse
        // (Relapse admission), and their three originals as New in August.
        // Six episodes, six admissions, one column each.
        Carbon::setTestNow('2026-09-05');

        $defaulted = $this->closedEpisode('SAM', 'defaulted', '500000020');
        $other = $this->closedEpisode('SAM', 'discharge_to_opt', '500000021');
        $this->closedEpisode('SAM', 'cured', '500000022');

        ChildFollowUpTransfer::readmitFromEpisode($defaulted, ['admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110]);
        ChildFollowUpTransfer::readmitFromEpisode($other, ['admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110]);
        ChildFollowUpTransfer::refer($this->screening(['child_id' => '500000022', 'sex' => 'M', 'muac_mm' => 110, 'has_oedema' => false]));

        $this->assertSame(6, FollowUpChild::query()->count());

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][self::AUGUST];
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(3, $august['sam_adm_6_23_new_male']);
        $this->assertSame(2, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(1, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $september['sam_adm_6_23_new_male']);

        $this->assertSame(6, $this->sum($sheet['totals'], '_adm_'), 'Six episodes, six admissions, no more.');
        $this->assertSame(3, $this->sum($sheet['totals'], '_dis_'), 'Three closures, three discharges.');
    }

    public function test_sam_new_admission_is_counted_under_new_by_age_and_sex(): void
    {
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'F', 'age' => 8, 'admission_date' => '2026-08-05']);
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'M', 'age' => 48, 'admission_date' => '2026-08-06']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['sam_adm_6_23_new_female']);
        $this->assertSame(1, $totals['sam_adm_24_59_new_male']);
        $this->assertSame(0, $totals['sam_adm_6_23_relapse_female']);
        $this->assertSame(0, $totals['sam_adm_6_23_readmission_female']);
        $this->assertSame(0, $totals['sam_oedema_adm_6_23_new_female']);
        $this->assertSame(0, $totals['mam_adm_6_23_new_female']);
    }

    public function test_sam_return_after_a_cure_is_counted_under_relapse_admission(): void
    {
        $this->episode([
            'id_number' => '500000004', 'admitted_with' => 'SAM', 'sex' => 'F', 'dob' => '2024-01-05',
            'admission_date' => '2026-05-01', 'discharge_date' => '2026-06-15', 'discharge_outcome' => 'cured',
        ]);
        $this->episode([
            'id_number' => '500000004', 'admitted_with' => 'SAM', 'sex' => 'F', 'dob' => '2024-01-05',
            'admission_date' => '2026-08-05', 'admission_type' => FollowUpChild::ADMISSION_NEW,
        ]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['sam_adm_24_59_relapse_female']);
        $this->assertSame(0, $totals['sam_adm_24_59_new_female']);
        $this->assertSame(0, $totals['sam_adm_24_59_readmission_female']);
    }

    public function test_sam_readmission_is_counted_under_readmission(): void
    {
        $previous = $this->closedEpisode('SAM', 'discharge_to_opt', '500000005');

        ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $september = $this->sheet(self::SEPTEMBER, self::SEPTEMBER)['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['sam_adm_6_23_new_male']);
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
    }

    // =================================================================
    // 7. SAM with oedema
    // =================================================================

    public function test_sam_with_oedema_admissions_are_counted_in_their_own_block(): void
    {
        // Three screenings referred by the module's own transfer, so each
        // episode is linked to the screening that raised it.
        Carbon::setTestNow('2026-08-10');

        $oedema = ChildFollowUpTransfer::refer($this->screening(['muac_mm' => 110, 'sex' => 'male', 'has_oedema' => true]));
        $plain = ChildFollowUpTransfer::refer($this->screening(['muac_mm' => 110, 'sex' => 'male', 'has_oedema' => false]));
        $mam = ChildFollowUpTransfer::refer($this->screening(['muac_mm' => 118, 'sex' => 'female', 'has_oedema' => true]));

        $this->assertNotNull($oedema);
        $this->assertNotNull($plain);
        $this->assertNotNull($mam);
        $this->assertSame('SAM', $oedema->admitted_with);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['sam_oedema_adm_6_23_new_male']);
        $this->assertSame(1, $totals['sam_adm_6_23_new_male'], 'The SAM admission without oedema.');
        $this->assertSame(0, $totals['sam_oedema_adm_6_23_new_female']);

        // Oedema does not move a MAM admission: the stored classification rules.
        $this->assertSame(1, $totals['mam_adm_6_23_new_female']);

        // Every admission is counted exactly once across the three blocks.
        $this->assertSame(3, $this->sum($totals, '_adm_'));
    }

    public function test_an_oedema_readmission_is_counted_as_a_readmission_without_a_screening_link(): void
    {
        Carbon::setTestNow('2026-08-10');

        $episode = ChildFollowUpTransfer::refer($this->screening(['muac_mm' => 110, 'sex' => 'male', 'has_oedema' => true, 'child_id' => '500000006']));
        $episode->update(['discharge_outcome' => 'referred_medical_inpt', 'discharge_date' => '2026-08-20']);

        ChildFollowUpTransfer::readmitFromEpisode($episode->fresh(), [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);

        $this->assertSame(1, $sheet['monthTotals'][self::AUGUST]['sam_oedema_adm_6_23_new_male']);
        // Readmitted from the episode itself, with no screening to carry an
        // oedema flag: a SAM readmission.
        $this->assertSame(1, $sheet['monthTotals'][self::SEPTEMBER]['sam_adm_6_23_readmission_male']);
        $this->assertSame(0, $sheet['monthTotals'][self::SEPTEMBER]['sam_oedema_adm_6_23_readmission_male']);
    }

    // =================================================================
    // 8-21. Discharges, outcome by outcome - MAM and SAM
    // =================================================================

    public function test_mam_recovered(): void
    {
        $this->assertDischargeCounted('MAM', 'cured', 'recovered');
    }

    public function test_mam_defaulted(): void
    {
        $this->assertDischargeCounted('MAM', 'defaulted', 'defaulted');
    }

    public function test_mam_died(): void
    {
        $this->assertDischargeCounted('MAM', 'died', 'died');
    }

    public function test_mam_non_responded(): void
    {
        $this->assertDischargeCounted('MAM', 'non_responded', 'no_response');
    }

    public function test_mam_medical_referral(): void
    {
        $this->assertDischargeCounted('MAM', 'referred_medical_inpt', 'referred_medical');
    }

    public function test_mam_discharged_other(): void
    {
        $this->assertDischargeCounted('MAM', 'discharge_to_other', 'other');
    }

    public function test_mam_discharged_unknown_has_no_stored_outcome_and_stays_blank(): void
    {
        $this->assertUnknownDischargeBlank('MAM');
    }

    public function test_sam_recovered(): void
    {
        $this->assertDischargeCounted('SAM', 'cured', 'recovered');
    }

    public function test_sam_defaulted(): void
    {
        $this->assertDischargeCounted('SAM', 'defaulted', 'defaulted');
    }

    public function test_sam_died(): void
    {
        $this->assertDischargeCounted('SAM', 'died', 'died');
    }

    public function test_sam_non_responded(): void
    {
        $this->assertDischargeCounted('SAM', 'non_responded', 'no_response');
    }

    public function test_sam_medical_referral(): void
    {
        $this->assertDischargeCounted('SAM', 'referred_medical_inpt', 'referred_medical');
    }

    public function test_sam_discharged_other(): void
    {
        $this->assertDischargeCounted('SAM', 'discharge_to_other', 'other');
    }

    public function test_sam_discharged_unknown_has_no_stored_outcome_and_stays_blank(): void
    {
        $this->assertUnknownDischargeBlank('SAM');
    }

    public function test_an_open_episode_with_a_discharge_date_but_no_closure_is_not_a_discharge(): void
    {
        $this->episode([
            'admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01',
            'discharge_date' => '2026-08-20', 'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(0, $this->sum($totals, '_dis_'));
        $this->assertSame(0, $totals['mam_los_6_23_male'], 'No discharge, no length of stay.');
        $this->assertSame(1, $totals['mam_adm_6_23_new_male'], 'The admission itself still counts.');
    }

    // =================================================================
    // 22-24. Age bands and sex
    // =================================================================

    public function test_age_6_to_23_months_is_taken_from_the_date_of_birth_at_admission(): void
    {
        foreach ([5, 6, 23] as $months) {
            $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => $months, 'admission_date' => '2026-08-15']);
        }

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(2, $totals['mam_adm_6_23_new_male'], '6 and 23 months are inside the band; 5 is not.');
        $this->assertSame(0, $totals['mam_adm_24_59_new_male']);
    }

    public function test_age_24_to_59_months_is_taken_from_the_date_of_birth_at_admission(): void
    {
        foreach ([24, 59, 60] as $months) {
            $this->episode(['admitted_with' => 'SAM', 'sex' => 'F', 'age' => $months, 'admission_date' => '2026-08-15']);
        }

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(2, $totals['sam_adm_24_59_new_female'], '24 and 59 months are inside the band; 60 is not.');
        $this->assertSame(0, $totals['sam_adm_6_23_new_female']);
    }

    public function test_the_stored_age_text_is_never_used_when_the_date_of_birth_is_known(): void
    {
        // The free-text age column says 40 months; the date of birth says 12.
        $episode = $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-15']);
        $episode->forceFill(['age' => '40 months'])->saveQuietly();

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(0, $totals['mam_adm_24_59_new_male']);
    }

    public function test_a_discharge_stays_in_the_band_the_case_was_admitted_into(): void
    {
        // Admitted at 23 months, discharged at 25: one case, one band.
        $this->episode([
            'admitted_with' => 'MAM', 'sex' => 'M', 'age' => 23, 'admission_date' => '2026-07-01',
            'discharge_date' => '2026-09-01', 'discharge_outcome' => 'cured',
        ]);

        $sheet = $this->sheet(self::JULY, self::SEPTEMBER);

        $this->assertSame(1, $sheet['monthTotals'][self::JULY]['mam_adm_6_23_new_male']);
        $this->assertSame(1, $sheet['monthTotals'][self::SEPTEMBER]['mam_dis_recovered_6_23_male']);
        $this->assertSame(0, $sheet['monthTotals'][self::SEPTEMBER]['mam_dis_recovered_24_59_male']);
    }

    public function test_male_and_female_are_taken_from_the_stored_sex(): void
    {
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-15']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'F', 'age' => 12, 'admission_date' => '2026-08-15']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'F', 'age' => 12, 'admission_date' => '2026-08-15']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_adm_6_23_new_male']);
        $this->assertSame(2, $totals['mam_adm_6_23_new_female']);
    }

    // =================================================================
    // 25-26. Average length of stay
    // =================================================================

    public function test_average_length_of_stay_mam_is_averaged_over_the_cases_of_the_month(): void
    {
        // 10 days, 30 days and 10 days: two discharge days, three cases.
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-11', 'discharge_outcome' => 'cured']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-31', 'discharge_outcome' => 'cured']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-21', 'discharge_date' => '2026-08-31', 'discharge_outcome' => 'cured']);
        // A September case, 4 days, in its own month.
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-09-01', 'discharge_date' => '2026-09-05', 'discharge_outcome' => 'cured']);

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $rows = collect($sheet['rows']);

        $this->assertSame(10.0, $rows->where('month', 'August')->firstWhere('day', 11)['mam_los_6_23_male']);
        $this->assertSame(20.0, $rows->where('month', 'August')->firstWhere('day', 31)['mam_los_6_23_male']);

        // (10 + 30 + 10) / 3, not the mean of the two daily means (15.0).
        $this->assertSame(16.7, $sheet['monthTotals'][self::AUGUST]['mam_los_6_23_male']);
        $this->assertSame(4.0, $sheet['monthTotals'][self::SEPTEMBER]['mam_los_6_23_male']);
        // (10 + 30 + 10 + 4) / 4 over the whole period.
        $this->assertSame(13.5, $sheet['totals']['mam_los_6_23_male']);

        $this->assertSame(0, $sheet['totals']['sam_los_6_23_male']);
    }

    public function test_average_length_of_stay_sam_uses_admission_and_discharge_dates(): void
    {
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'F', 'age' => 30, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-15', 'discharge_outcome' => 'cured']);
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'F', 'age' => 30, 'admission_date' => '2026-08-03', 'discharge_date' => '2026-08-24', 'discharge_outcome' => 'non_responded']);

        $sheet = $this->sheet(self::AUGUST, self::AUGUST);

        // (14 + 21) / 2
        $this->assertSame(17.5, $sheet['monthTotals'][self::AUGUST]['sam_los_24_59_female']);
        $this->assertSame(0, $sheet['monthTotals'][self::AUGUST]['sam_los_6_23_female']);
        $this->assertSame(0, $sheet['monthTotals'][self::AUGUST]['mam_los_24_59_female']);
    }

    // =================================================================
    // 27-28. SAM referred to SC, caregivers counselled
    // =================================================================

    public function test_sam_cases_referred_to_sc_come_from_the_inpatient_medical_referral_outcome(): void
    {
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-09', 'discharge_outcome' => 'referred_medical_inpt']);
        // A transfer to another OTP is neither a medical referral nor an SC
        // referral: it is a case discharged other.
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'F', 'age' => 30, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-09', 'discharge_outcome' => 'discharge_to_opt']);
        // A MAM referral is a MAM discharge, never a SAM referral.
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-09', 'discharge_outcome' => 'referred_medical_inpt']);
        // A cure is not a referral.
        $this->episode(['admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-09', 'discharge_outcome' => 'cured']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['sam_referred_6_23_male']);
        $this->assertSame(0, $totals['sam_referred_24_59_female']);
        $this->assertSame(0, $totals['sam_referred_6_23_female']);
        $this->assertSame(1, $this->sum($totals, 'sam_referred_'));

        $this->assertSame(1, $totals['sam_dis_referred_medical_6_23_male']);
        $this->assertSame(1, $totals['mam_dis_referred_medical_6_23_male']);
        $this->assertSame(0, $totals['sam_dis_referred_medical_24_59_female']);
        $this->assertSame(1, $totals['sam_dis_other_24_59_female']);
    }

    public function test_caregivers_counselled_has_no_source_and_stays_blank(): void
    {
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-05']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);
        $unsupported = MealReportService::unsupportedColumns()[self::CMAM];

        foreach (['new_male', 'new_female', 'returning_male', 'returning_female'] as $column) {
            $this->assertNull($totals["cg_counselled_{$column}"]);
            $this->assertContains("cg_counselled_{$column}", $unsupported);
        }

        // The columns this task filled in are no longer declared unsupported.
        foreach ($unsupported as $key) {
            $this->assertStringNotContainsString('_relapse_', $key);
            $this->assertStringNotContainsString('_readmission_', $key);
            $this->assertStringStartsNotWith('sam_oedema_adm_', $key);
        }
    }

    // =================================================================
    // 29-31. Month, year and monthly totals
    // =================================================================

    public function test_an_admission_and_its_discharge_each_belong_to_their_own_month(): void
    {
        $this->episode([
            'admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-25',
            'discharge_date' => '2026-09-10', 'discharge_outcome' => 'cured',
        ]);

        $sheet = $this->sheet(self::JULY, self::SEPTEMBER);
        $rows = collect($sheet['rows']);

        $august = $rows->firstWhere('month', 'August');
        $september = $rows->firstWhere('month', 'September');

        $this->assertSame(25, $august['day']);
        $this->assertSame(1, $august['mam_adm_6_23_new_male']);
        $this->assertSame(0, $august['mam_dis_recovered_6_23_male']);

        $this->assertSame(10, $september['day']);
        $this->assertSame(0, $september['mam_adm_6_23_new_male']);
        $this->assertSame(1, $september['mam_dis_recovered_6_23_male']);

        $this->assertSame(0, $this->sum($sheet['monthTotals'][self::JULY], '_adm_'));
        $this->assertSame(1, $sheet['monthTotals'][self::AUGUST]['mam_adm_6_23_new_male']);
        $this->assertSame(0, $sheet['monthTotals'][self::AUGUST]['mam_dis_recovered_6_23_male']);
        $this->assertSame(1, $sheet['monthTotals'][self::SEPTEMBER]['mam_dis_recovered_6_23_male']);
    }

    public function test_the_same_month_of_another_year_is_not_reported(): void
    {
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2025-08-10']);
        $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-10']);

        $this->assertSame(1, $this->totals(self::AUGUST, self::AUGUST)['mam_adm_6_23_new_male']);

        $lastYear = app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(2025, self::AUGUST, self::AUGUST), self::SITE)[self::CMAM];

        $this->assertSame(1, $lastYear['totals']['mam_adm_6_23_new_male']);
    }

    public function test_each_month_has_its_own_total_and_they_are_independent(): void
    {
        foreach (['2026-08-03', '2026-08-03', '2026-08-12'] as $date) {
            $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => $date]);
        }

        foreach (['2026-09-01', '2026-09-02', '2026-09-02', '2026-09-15', '2026-09-30'] as $date) {
            $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => $date]);
        }

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);

        $this->assertSame(3, $sheet['monthTotals'][self::AUGUST]['mam_adm_6_23_new_male'], 'TOTAL AUGUST = 3');
        $this->assertSame(5, $sheet['monthTotals'][self::SEPTEMBER]['mam_adm_6_23_new_male'], 'TOTAL SEPTEMBER = 5, not 8');
        $this->assertSame(8, $sheet['totals']['mam_adm_6_23_new_male'], 'The period total is the two months together.');

        $this->assertSame('Total August', $sheet['monthTotals'][self::AUGUST]['mba']);
        $this->assertSame('Total September', $sheet['monthTotals'][self::SEPTEMBER]['mba']);

        // The monthly total is the sum of that month's day rows, exactly.
        $rows = collect($sheet['rows']);
        $this->assertSame(3, $rows->where('month', 'August')->sum('mam_adm_6_23_new_male'));
        $this->assertSame(5, $rows->where('month', 'September')->sum('mam_adm_6_23_new_male'));
    }

    // =================================================================
    // 32. One workbook, several months
    // =================================================================

    public function test_the_cmam_sheet_of_one_workbook_holds_every_month_with_its_own_total_row(): void
    {
        foreach (['2026-08-03', '2026-08-03', '2026-08-12'] as $date) {
            $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => $date]);
        }

        foreach (['2026-09-01', '2026-09-02', '2026-09-02', '2026-09-15', '2026-09-30'] as $date) {
            $this->episode(['admitted_with' => 'MAM', 'sex' => 'M', 'age' => 12, 'admission_date' => $date]);
        }

        $data = app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(self::YEAR, self::AUGUST, self::SEPTEMBER), self::SITE);

        $path = tempnam(sys_get_temp_dir(), 'meal') . '.xlsx';
        file_put_contents($path, Excel::raw(new MealReportExport($data), \Maatwebsite\Excel\Excel::XLSX));

        $book = IOFactory::load($path);

        // The template's sheets, and no sheet per month.
        $this->assertSame(MealReportLayout::sheets(), $book->getSheetNames());

        $sheet = $book->getSheetByName(self::CMAM);
        $first = MealReportLayout::FIRST_DATA_ROW[self::CMAM];
        $column = array_search('mam_adm_6_23_new_male', MealReportLayout::columns(self::CMAM), true) + 1;

        $read = [];
        for ($row = $first; $row < $first + 10; $row++) {
            $read[] = [
                (string) $sheet->getCell([1, $row])->getValue(),
                (string) $sheet->getCell([2, $row])->getValue(),
                $sheet->getCell([3, $row])->getValue(),
                $sheet->getCell([$column, $row])->getValue(),
            ];
        }

        // A Total row's label spans MBA to DAY (the cells are merged), so its
        // MONTH and DAY cells read blank.
        $this->assertSame([
            [self::SITE, 'August', 3, 2],
            [self::SITE, 'August', 12, 1],
            ['Total August', '', null, 3],
            [self::SITE, 'September', 1, 1],
            [self::SITE, 'September', 2, 2],
            [self::SITE, 'September', 15, 1],
            [self::SITE, 'September', 30, 1],
            ['Total September', '', null, 5],
            ['Total', '', null, 8],
            ['', '', null, null],
        ], $read);

        @unlink($path);
    }

    // =================================================================
    // 33. No double counting
    // =================================================================

    public function test_an_episode_is_one_admission_and_at_most_one_discharge(): void
    {
        $episode = $this->episode([
            'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 12, 'admission_date' => '2026-08-01',
            'discharge_date' => '2026-08-20', 'discharge_outcome' => 'cured',
        ]);

        // Visits are the journey inside the episode, not further events.
        foreach ([1, 2, 3, 4] as $number) {
            $episode->visits()->create(['visit_number' => $number, 'visit_date' => '2026-08-0' . $number, 'muac' => 110]);
        }

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $this->sum($totals, '_adm_'));
        $this->assertSame(1, $this->sum($totals, '_dis_'));
    }

    public function test_a_child_with_two_episodes_is_two_admissions_not_three(): void
    {
        $previous = $this->closedEpisode('SAM', 'referred_medical_inpt', '500000007');

        ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $totals = $this->totals(self::AUGUST, self::SEPTEMBER);

        $this->assertSame(2, $this->sum($totals, '_adm_'));
        $this->assertSame(1, $totals['sam_adm_6_23_new_male']);
        $this->assertSame(1, $totals['sam_adm_6_23_readmission_male']);
        $this->assertSame(1, $this->sum($totals, '_dis_'), 'Only the closed episode has been discharged.');
    }

    // =================================================================
    // 34-36. Closed history, readmission as its own episode
    // =================================================================

    public function test_a_closed_historical_episode_is_reported_in_the_months_it_happened(): void
    {
        $episode = $this->episode([
            'admitted_with' => 'MAM', 'sex' => 'F', 'age' => 12, 'admission_date' => '2026-07-06',
            'discharge_date' => '2026-07-27', 'discharge_outcome' => 'cured',
        ]);

        $this->assertTrue($episode->isLocked(), 'A cured episode is closed.');

        $july = $this->totals(self::JULY, self::JULY);

        $this->assertSame(1, $july['mam_adm_6_23_new_female']);
        $this->assertSame(1, $july['mam_dis_recovered_6_23_female']);
        $this->assertSame(21.0, $july['mam_los_6_23_female']);

        // And nowhere else.
        $august = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(0, $this->sum($august, '_adm_'));
        $this->assertSame(0, $this->sum($august, '_dis_'));
    }

    public function test_a_readmission_is_a_separate_new_episode_starting_at_visit_1(): void
    {
        $previous = $this->closedEpisode('SAM', 'referred_medical_inpt', '500000008');

        $readmission = ChildFollowUpTransfer::readmitFromEpisode($previous, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $this->assertNotNull($readmission);
        $this->assertNotSame($previous->getKey(), $readmission->getKey());
        $this->assertTrue($readmission->isReadmission());
        $this->assertSame($previous->getKey(), $readmission->previous_follow_up_child_id);
        $this->assertSame([1], $readmission->visits()->pluck('visit_number')->all());
        $this->assertSame(2, FollowUpChild::where('id_number', '500000008')->count());

        $september = $this->sheet(self::SEPTEMBER, self::SEPTEMBER)['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $september['sam_adm_6_23_readmission_male']);
    }

    public function test_an_old_closed_episode_and_its_readmission_are_reported_as_two_separate_episodes(): void
    {
        // Old episode: admitted 1 August, two visits, referred to inpatient
        // care on 20 August. Closed.
        $old = $this->closedEpisode('SAM', 'referred_medical_inpt', '500000009');
        $before = $this->snapshot($old);

        // The child returns on 5 September: a readmission.
        $new = ChildFollowUpTransfer::readmitFromEpisode($old, [
            'admission_date' => '2026-09-05', 'visit_date' => '2026-09-05', 'muac' => 110,
        ]);

        $this->assertNotNull($new);
        $this->assertSame([1], $new->visits()->pluck('visit_number')->all(), 'The new episode starts at visit 1.');
        $this->assertSame($before, $this->snapshot($old), 'The old episode is not touched.');

        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][self::AUGUST];
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        // OLD episode: its own historical admission and discharge, in August.
        $this->assertSame(1, $august['sam_adm_6_23_new_male']);
        $this->assertSame(1, $august['sam_dis_referred_medical_6_23_male']);
        $this->assertSame(1, $august['sam_referred_6_23_male']);
        $this->assertSame(19.0, $august['sam_los_6_23_male']);

        // NEW episode: under Readmission, in September, and nothing else.
        $this->assertSame(1, $september['sam_adm_6_23_readmission_male']);
        $this->assertSame(0, $september['sam_adm_6_23_new_male'], 'The readmission must not be counted as a first-ever New admission.');
        $this->assertSame(0, $september['sam_adm_6_23_relapse_male']);
        $this->assertSame(0, $this->sum($september, '_dis_'), 'The readmission is still open.');

        // Over the period: two admissions, one discharge - never merged, never doubled.
        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'));
        $this->assertSame(1, $this->sum($sheet['totals'], '_dis_'));
    }

    // =================================================================
    // 37. Non Responded
    // =================================================================

    public function test_a_non_responded_episode_is_counted_as_did_not_respond_and_nothing_else(): void
    {
        $episode = $this->episode([
            'id_number' => '500000010', 'admitted_with' => 'MAM', 'sex' => 'F', 'age' => 12,
            'admission_date' => '2026-08-01', 'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        // Several attended visits with no improvement, then the person
        // closes the episode on the outcome field, as the module does.
        foreach ([1 => '2026-08-01', 2 => '2026-08-08', 3 => '2026-08-15', 4 => '2026-08-22', 5 => '2026-08-29'] as $number => $date) {
            $episode->visits()->create(['visit_number' => $number, 'visit_date' => $date, 'muac' => 118]);
        }

        $episode->update(['discharge_outcome' => 'non_responded', 'discharge_date' => '2026-08-29']);
        $episode->refresh();

        $this->assertTrue($episode->isLocked(), 'Non Responded closes the episode.');
        $this->assertSame(5, $episode->visits()->count(), 'The visit history stays.');
        $this->assertSame(1, FollowUpChild::where('id_number', '500000010')->count(), 'No readmission is opened.');
        $this->assertFalse($episode->canBeReadmitted());

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['mam_dis_no_response_6_23_female']);

        $this->assertSame(0, $totals['mam_dis_recovered_6_23_female']);
        $this->assertSame(0, $totals['mam_dis_defaulted_6_23_female']);
        $this->assertSame(0, $totals['mam_adm_6_23_readmission_female']);
        $this->assertSame(0, $totals['mam_adm_6_23_relapse_female']);
        $this->assertSame(1, $this->sum($totals, '_dis_'), 'One discharge, in one column.');
        $this->assertSame(1, $totals['mam_adm_6_23_new_female'], 'Its admission on 1 August is the one admission.');
        $this->assertSame(28.0, $totals['mam_los_6_23_female']);
    }

    // =================================================================
    // Defaulter and medical referral, through the stored history
    // =================================================================

    public function test_a_defaulted_episode_is_counted_under_defaulted_from_its_stored_visits(): void
    {
        $episode = $this->episode([
            'id_number' => '500000011', 'admitted_with' => 'SAM', 'sex' => 'M', 'age' => 30,
            'admission_date' => '2026-08-01', 'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-01', 'muac' => 110]);
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-08', 'status' => FollowUpChildVisit::STATUS_MISSED]);
        $episode->visits()->create(['visit_number' => 3, 'visit_date' => '2026-08-15', 'status' => FollowUpChildVisit::STATUS_MISSED]);

        $this->assertTrue($episode->fresh()->meetsDefaulterRule(), 'Two consecutive missed visits: the programme rule.');

        // The person records the outcome; the rule itself writes nothing.
        $episode->update(['discharge_outcome' => 'defaulted', 'discharge_date' => '2026-08-15']);

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        $this->assertSame(1, $totals['sam_dis_defaulted_24_59_male']);
        $this->assertSame(0, $totals['sam_dis_no_response_24_59_male']);
        $this->assertSame(0, $totals['sam_dis_recovered_24_59_male']);
        $this->assertSame(0, $totals['sam_adm_24_59_readmission_male']);
        $this->assertSame(1, $this->sum($totals, '_dis_'));
        $this->assertSame(1, FollowUpChild::where('id_number', '500000011')->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array{rows: array, totals: array, monthTotals: array, monthStarts: array, review: array} */
    private function sheet(int $from, int $to, ?string $site = self::SITE): array
    {
        return app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(self::YEAR, $from, $to), $site)[self::CMAM];
    }

    /** @return array<string, int|float|string|null> */
    private function totals(int $from, int $to, ?string $site = self::SITE): array
    {
        return $this->sheet($from, $to, $site)['totals'];
    }

    /** The sum of every numeric column whose key contains the fragment. */
    private function sum(array $row, string $fragment): int|float
    {
        $total = 0;

        foreach ($row as $key => $value) {
            if (str_contains($key, $fragment) && is_numeric($value)) {
                $total += $value;
            }
        }

        return $total;
    }

    /**
     * A cured SAM/MAM episode in August, then the same child screened at
     * the programme again in September: the transfer opens a readmission
     * after relapse linked to the cured episode, and the report counts it
     * under Relapse admission (never Readmission), the cure under
     * Recovered, and nothing twice.
     */
    private function assertRelapseCounted(string $programme, int $muac, string $idNumber): void
    {
        Carbon::setTestNow('2026-09-05');

        $cured = $this->closedEpisode($programme, 'cured', $idNumber);

        $relapse = ChildFollowUpTransfer::refer($this->screening([
            'child_id' => $idNumber, 'sex' => 'M', 'muac_mm' => $muac, 'has_oedema' => false,
        ]));

        $this->assertNotNull($relapse);
        $this->assertTrue($relapse->isReadmission());
        $this->assertSame($cured->getKey(), $relapse->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $relapse->readmissionClassification());
        $this->assertSame([1], $relapse->visits->pluck('visit_number')->all());

        $prefix = strtolower($programme);
        $sheet = $this->sheet(self::AUGUST, self::SEPTEMBER);
        $august = $sheet['monthTotals'][self::AUGUST];
        $september = $sheet['monthTotals'][self::SEPTEMBER];

        $this->assertSame(1, $august["{$prefix}_adm_6_23_new_male"]);
        $this->assertSame(1, $august["{$prefix}_dis_recovered_6_23_male"]);

        $this->assertSame(1, $september["{$prefix}_adm_6_23_relapse_male"], 'A return after a cure is a Relapse admission.');
        $this->assertSame(0, $september["{$prefix}_adm_6_23_new_male"], 'Visit 1 of a readmission is not a New admission.');
        $this->assertSame(0, $september["{$prefix}_adm_6_23_readmission_male"], 'Never under Readmission.');

        $this->assertSame(2, $this->sum($sheet['totals'], '_adm_'), 'Two episodes, two admissions, no more.');
    }

    private function assertDischargeCounted(string $programme, string $stored, string $column): void
    {
        $this->episode([
            'admitted_with' => $programme, 'sex' => 'F', 'age' => 30,
            'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => $stored,
        ]);

        $totals = $this->totals(self::AUGUST, self::AUGUST);
        $prefix = strtolower($programme);
        $other = $prefix === 'mam' ? 'sam' : 'mam';

        $this->assertSame(1, $totals["{$prefix}_dis_{$column}_24_59_female"], "[{$stored}] must fill [{$column}].");

        foreach (array_unique(self::OUTCOME_COLUMNS) as $candidate) {
            if ($candidate !== $column) {
                $this->assertSame(0, $totals["{$prefix}_dis_{$candidate}_24_59_female"], "[{$stored}] must not also fill [{$candidate}].");
            }
        }

        $this->assertSame(0, $this->sum($totals, "{$other}_dis_"), 'A discharge belongs to the programme it was admitted to.');
        $this->assertSame(1, $totals["{$prefix}_adm_24_59_new_female"], 'The admission is counted once, as New.');
        $this->assertSame(0, $totals["{$prefix}_adm_24_59_readmission_female"]);
        $this->assertSame(19.0, $totals["{$prefix}_los_24_59_female"]);
    }

    private function assertUnknownDischargeBlank(string $programme): void
    {
        $prefix = strtolower($programme);

        foreach (array_keys(self::OUTCOME_COLUMNS) as $stored) {
            $this->episode([
                'admitted_with' => $programme, 'sex' => 'M', 'age' => 12,
                'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => $stored,
            ]);
        }

        $totals = $this->totals(self::AUGUST, self::AUGUST);

        // No stored outcome means "unknown", so the column is blank - not
        // zero, and never a catch-all for the outcomes above.
        $this->assertNull($totals["{$prefix}_dis_unknown_6_23_male"]);
        $this->assertContains("{$prefix}_dis_unknown_6_23_male", MealReportService::unsupportedColumns()[self::CMAM]);
        $this->assertSame(count(self::OUTCOME_COLUMNS), $this->sum($totals, "{$prefix}_dis_"));
    }

    /**
     * An episode admitted on 1 August at 12 months, seen twice and closed on
     * 20 August with the given outcome.
     */
    private function closedEpisode(string $programme, string $outcome, string $idNumber): FollowUpChild
    {
        $episode = $this->episode([
            'id_number' => $idNumber, 'admitted_with' => $programme, 'sex' => 'M', 'dob' => '2025-08-01',
            'admission_date' => '2026-08-01', 'discharge_date' => '2026-08-20', 'discharge_outcome' => $outcome,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-01', 'muac' => $programme === 'SAM' ? 110 : 118]);
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-08', 'muac' => $programme === 'SAM' ? 111 : 119]);

        return $episode->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(FollowUpChild $episode): array
    {
        $episode = $episode->fresh();

        return [
            'attributes' => $episode->getAttributes(),
            'visits' => $episode->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    private function episode(array $attributes): FollowUpChild
    {
        $dob = $attributes['dob']
            ?? Carbon::parse($attributes['admission_date'])->subMonths($attributes['age'])->toDateString();

        return FollowUpChild::create([
            'id_number' => $attributes['id_number'] ?? (string) fake()->unique()->numberBetween(100000000, 999999999),
            'child_name' => 'Test child',
            'sex' => $attributes['sex'],
            'dob' => $dob,
            'mobile_number' => '0599123456',
            'shelter_name' => $attributes['shelter_name'] ?? 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => $attributes['admitted_with'],
            'admission_type' => $attributes['admission_type'] ?? null,
            'admission_date' => $attributes['admission_date'],
            'discharge_date' => $attributes['discharge_date'] ?? null,
            'discharge_outcome' => $attributes['discharge_outcome'] ?? null,
        ]);
    }

    /** A Children screening at this site, 12 months old on the day. */
    private function screening(array $attributes): Child
    {
        $date = Carbon::today()->toDateString();

        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $attributes['child_id'] ?? (string) fake()->unique()->numberBetween(100000000, 999999999),
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'sex' => $attributes['sex'],
            'date_of_birth' => Carbon::parse($date)->subMonths(12)->toDateString(),
            'muac_mm' => $attributes['muac_mm'],
            'has_oedema' => $attributes['has_oedema'],
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => self::SITE,
        ]);
    }
}
