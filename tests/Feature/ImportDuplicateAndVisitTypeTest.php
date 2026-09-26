<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * C2 - what makes a row a duplicate, and what makes a person new or returning.
 *
 * These are two different questions and they used to be run together. A mother
 * at her fourth session is returning and her row must import; the same session
 * uploaded twice is a duplicate and must not. Getting that backwards either
 * refuses valid work or stores it twice, and both happened: five of the six
 * modules had no duplicate check at all, and the one that did threw the row
 * away without saying anything.
 *
 * So every module is asked the same three questions:
 *
 *   - a first record for this ID: new;
 *   - the same ID on a later date: not a duplicate, and a follow-up;
 *   - the same ID on the same date: a duplicate, reported by row number, and
 *     not written.
 *
 * A duplicate does not cancel the file. The teams add the new month to last
 * month's sheet and upload the whole thing, so the rows that were already in
 * the system are named and skipped while the new ones import - which is the
 * only reading of "do not silently skip" that leaves that habit working.
 */
class ImportDuplicateAndVisitTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function headings(string $key): array
    {
        return (new ImportSchema(ImportDefinition::get($key)))->headings();
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $cells
     */
    private function row(string $key, array $base, array $cells = []): array
    {
        $headings = $this->headings($key);
        $row = array_fill(0, count($headings), null);

        foreach (array_merge($base, $cells) as $heading => $value) {
            $index = array_search($heading, $headings, true);

            $this->assertNotFalse($index, "Heading [{$heading}] is not in the {$key} template.");

            $row[$index] = $value;
        }

        return $row;
    }

    /**
     * @param  array<int, array>  $rows
     * @return array{imported: int, errors: array, skipped: array}
     */
    private function import(string $key, array $rows): array
    {
        $export = new class(array_merge([$this->headings($key)], $rows)) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = $key . '-dup-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            \Storage::disk('local')->path($name),
        );
    }

    /**
     * A result that imported everything it was given and reported nothing.
     */
    private function assertImportedCleanly(array $result, int $expected): void
    {
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame($expected, $result['imported']);
    }

    /**
     * A result that reported exactly one duplicate, naming the given row.
     */
    private function assertSkippedAsDuplicate(array $result, int $rowNumber, string $message): void
    {
        $this->assertSame([], $result['errors'], 'A duplicate must not cancel the file.');
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString((string) $rowNumber, $result['skipped'][0]);
        $this->assertStringContainsString($message, $result['skipped'][0]);
    }

    // =================================================================
    // C2.1 Children
    // =================================================================

    private function childRow(array $cells = []): array
    {
        return $this->row('children', [
            __('fields.name') => 'طفل الاستيراد',
            __('fields.child_id') => '123456789',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-08-20',
            __('fields.governorate') => 'gaza',
            __('fields.sex') => 'ذكر',
            __('fields.muac_mm') => '130',
        ], $cells);
    }

    public function test_a_child_id_the_system_has_never_seen_is_new(): void
    {
        $this->assertImportedCleanly($this->import('children', [$this->childRow()]), 1);

        $this->assertSame('new', Child::first()->visit_type);
    }

    public function test_the_same_child_on_a_later_date_is_a_follow_up_and_not_a_duplicate(): void
    {
        $this->import('children', [$this->childRow()]);

        $result = $this->import('children', [$this->childRow([
            __('fields.date_of_reporting') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(2, Child::where('child_id', '123456789')->count());

        $later = Child::where('child_id', '123456789')->orderByDesc('date_of_reporting')->first();
        $this->assertSame('follow_up', $later->visit_type);
    }

    public function test_the_same_child_on_the_same_date_is_a_duplicate(): void
    {
        $this->import('children', [$this->childRow()]);

        $result = $this->import('children', [$this->childRow()]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_visit'));
        $this->assertSame(1, Child::where('child_id', '123456789')->count());
    }

    /**
     * The whole point of reporting rather than cancelling. A month appended to
     * last month's file has to import while the rows already in the system are
     * named and left alone.
     */
    public function test_a_re_uploaded_file_with_a_new_month_appended_imports_the_new_month(): void
    {
        $this->import('children', [$this->childRow()]);

        $result = $this->import('children', [
            $this->childRow(),
            $this->childRow([__('fields.date_of_reporting') => '2026-09-20']),
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_visit'));
        $this->assertSame(2, Child::where('child_id', '123456789')->count());
    }

    /**
     * A first visit is a first visit whatever the measurement says. The SAM/MAM
     * referral that a first SAM reading opens is the referral module's own
     * business and is not touched here.
     */
    public function test_a_first_visit_is_new_whether_the_reading_is_normal_mam_or_sam(): void
    {
        foreach ([['111111111', '130'], ['222222222', '120'], ['333333333', '110']] as [$id, $muac]) {
            $result = $this->import('children', [$this->childRow([
                __('fields.child_id') => $id,
                __('fields.muac_mm') => $muac,
            ])]);

            $this->assertImportedCleanly($result, 1);

            $this->assertSame(
                'new',
                Child::where('child_id', $id)->first()->visit_type,
                "A first visit measured at {$muac} mm must be new.",
            );
        }

        // And the classification itself is untouched: the readings above are
        // Normal, MAM and SAM.
        $this->assertSame('Normal', Child::where('child_id', '111111111')->first()->fi);
        $this->assertSame('MAM', Child::where('child_id', '222222222')->first()->fi);
        $this->assertSame('SAM', Child::where('child_id', '333333333')->first()->fi);
    }

    // =================================================================
    // C2.2 Pregnant / Lactating Women
    // =================================================================

    private function pregnantRow(array $cells = []): array
    {
        return $this->row('pregnant', [
            __('fields.full_name_ar') => 'أم الاستيراد',
            __('fields.mother_id') => '123456789',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-08-20',
            __('fields.governorate') => 'gaza',
            __('fields.status_type') => 'pregnant',
        ], $cells);
    }

    private function latestWoman(): PregnantLactatingWoman
    {
        return PregnantLactatingWoman::where('mother_id', '123456789')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function firstStatuses(): array
    {
        return [
            'pregnant' => ['pregnant'],
            'breastfeeding' => ['lactating'],
            'pregnant and breastfeeding' => ['pregnant_lactating'],
        ];
    }

    /**
     * @dataProvider firstStatuses
     */
    public function test_a_mother_id_the_system_has_never_seen_is_new(string $status): void
    {
        $result = $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $status,
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame('new', $this->latestWoman()->visit_type);
    }

    /**
     * The six transitions, all of which are a new admission into a different
     * care cycle - including the four involving the composite status, which is
     * what C2.2 is about.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statusTransitions(): array
    {
        return [
            'pregnant to breastfeeding' => ['pregnant', 'lactating'],
            'breastfeeding to pregnant' => ['lactating', 'pregnant'],
            'pregnant to pregnant and breastfeeding' => ['pregnant', 'pregnant_lactating'],
            'breastfeeding to pregnant and breastfeeding' => ['lactating', 'pregnant_lactating'],
            'pregnant and breastfeeding to pregnant' => ['pregnant_lactating', 'pregnant'],
            'pregnant and breastfeeding to breastfeeding' => ['pregnant_lactating', 'lactating'],
        ];
    }

    /**
     * @dataProvider statusTransitions
     */
    public function test_every_change_of_status_is_a_new_admission(string $from, string $to): void
    {
        $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $from,
        ])]);

        $result = $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $to,
            __('fields.date_of_reporting') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(
            'new',
            $this->latestWoman()->visit_type,
            "[{$from}] -> [{$to}] must be a new admission, not a follow-up.",
        );
    }

    /**
     * @dataProvider firstStatuses
     */
    public function test_the_same_status_on_a_later_date_is_a_follow_up(string $status): void
    {
        $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $status,
        ])]);

        $result = $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $status,
            __('fields.date_of_reporting') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame('follow_up', $this->latestWoman()->visit_type);
    }

    public function test_the_same_mother_in_the_same_status_on_the_same_date_is_a_duplicate(): void
    {
        $this->import('pregnant', [$this->pregnantRow()]);

        $result = $this->import('pregnant', [$this->pregnantRow()]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_visit'));
        $this->assertSame(1, PregnantLactatingWoman::where('mother_id', '123456789')->count());
    }

    /**
     * The status is not part of the duplicate key.
     *
     * A mother has one visit per reporting date. Recording her as pregnant and
     * again as breastfeeding on the same day is that one visit written down
     * twice, not two visits - whatever the two rows say about her status. The
     * key was briefly mother + status + date, and under it both rows stored.
     *
     * @dataProvider differingSameDayStatuses
     */
    public function test_the_same_mother_on_the_same_date_is_a_duplicate_whatever_the_status(
        string $first,
        string $second,
    ): void {
        $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $first,
        ])]);

        $result = $this->import('pregnant', [$this->pregnantRow([
            // The same reporting date; only the status differs.
            __('fields.status_type') => $second,
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_visit'));
        $this->assertSame(
            1,
            PregnantLactatingWoman::where('mother_id', '123456789')->count(),
            "[{$first}] then [{$second}] on one day must not store a second record.",
        );

        // And the record that is there is the first one, untouched.
        $this->assertSame($first, $this->latestWoman()->status_type);
    }

    /**
     * Every pair of different statuses, in both directions.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differingSameDayStatuses(): array
    {
        $statuses = ['pregnant', 'lactating', 'pregnant_lactating'];
        $pairs = [];

        foreach ($statuses as $first) {
            foreach ($statuses as $second) {
                if ($first !== $second) {
                    $pairs["{$first} then {$second}"] = [$first, $second];
                }
            }
        }

        return $pairs;
    }

    /**
     * The other half of the same rule: a different date is a different visit,
     * and the visit type it is given is whatever the status transition says -
     * which the duplicate change did not touch.
     *
     * @dataProvider differingSameDayStatuses
     */
    public function test_the_same_mother_on_a_different_date_with_a_different_status_is_not_a_duplicate(
        string $first,
        string $second,
    ): void {
        $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $first,
        ])]);

        $result = $this->import('pregnant', [$this->pregnantRow([
            __('fields.status_type') => $second,
            __('fields.date_of_reporting') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(2, PregnantLactatingWoman::where('mother_id', '123456789')->count());

        // Every change of status is a new admission; that rule is unchanged.
        $this->assertSame('new', $this->latestWoman()->visit_type);
    }

    /**
     * Two rows for one mother on one day, inside a single upload. The second is
     * compared against the first once it is stored, not only against what was
     * in the system before the file was opened.
     */
    public function test_two_rows_for_one_mother_on_one_day_in_the_same_file_store_once(): void
    {
        $result = $this->import('pregnant', [
            $this->pregnantRow([__('fields.status_type') => 'pregnant']),
            $this->pregnantRow([__('fields.status_type') => 'lactating']),
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 3, __('fields.import_duplicate_visit'));
        $this->assertSame(1, PregnantLactatingWoman::where('mother_id', '123456789')->count());
    }

    // =================================================================
    // C2.3 Mother-to-Mother
    // =================================================================

    private function motherToMotherRow(array $cells = []): array
    {
        return $this->row('mother_to_mother', [
            __('fields.session_date') => '2026-08-20',
            __('fields.session_group_number') => '1',
            __('fields.session_subject') => 'bf_support',
            __('fields.locality') => 'mosaab_camp',
            __('fields.shelter_name') => 'مخيم مصعب',
            __('fields.id_number') => '123456789',
            __('fields.full_name_ar') => 'مشاركة الاستيراد',
            __('fields.category') => 'grandmothers',
            __('fields.marital_status') => 'married',
        ], $cells);
    }

    public function test_a_first_mother_to_mother_session_is_new_and_a_later_one_is_a_follow_up(): void
    {
        $this->assertImportedCleanly($this->import('mother_to_mother', [$this->motherToMotherRow()]), 1);

        $this->assertSame('new', MotherToMotherSession::first()->visit_type);

        $result = $this->import('mother_to_mother', [$this->motherToMotherRow([
            __('fields.session_date') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);

        $later = MotherToMotherSession::orderByDesc('session_date')->first();
        $this->assertSame('follow_up', $later->visit_type);
    }

    public function test_the_same_mother_to_mother_session_on_the_same_date_is_a_duplicate(): void
    {
        $this->import('mother_to_mother', [$this->motherToMotherRow()]);

        $result = $this->import('mother_to_mother', [$this->motherToMotherRow()]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_session'));
        $this->assertSame(1, MotherToMotherSession::count());
    }

    // =================================================================
    // C2.4 Individual Counseling
    // =================================================================

    private function counselingRow(array $cells = []): array
    {
        return $this->row('individual_counseling', [
            __('fields.date') => '2026-08-20',
            __('fields.child_name') => 'طفل الإرشاد',
            __('fields.mother_id_number') => '123456789',
            __('fields.mother_name') => 'أم الإرشاد',
        ], $cells);
    }

    /**
     * The two visit types are independent, and both are read off the sheet:
     * one record carries a child and a mother, and either of them may be new
     * while the other is returning. No child ID is invented to tell them apart,
     * and the two are never collapsed into one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function counselingVisitTypePairs(): array
    {
        return [
            'both new' => ['new', 'new'],
            'both returning' => ['follow_up', 'follow_up'],
            'child returning, mother new' => ['follow_up', 'new'],
            'child new, mother returning' => ['new', 'follow_up'],
        ];
    }

    /**
     * @dataProvider counselingVisitTypePairs
     */
    public function test_the_child_and_mother_visit_types_are_independent(string $child, string $mother): void
    {
        $result = $this->import('individual_counseling', [$this->counselingRow([
            __('fields.child_visit_type') => __('fields.' . $child),
            __('fields.mother_visit_type') => __('fields.' . $mother),
        ])]);

        $this->assertImportedCleanly($result, 1);

        $record = IndividualCounseling::first();

        $this->assertSame($child, $record->child_visit_type);
        $this->assertSame($mother, $record->mother_visit_type);
    }

    public function test_the_same_mother_counselled_again_on_a_later_date_is_not_a_duplicate(): void
    {
        $this->import('individual_counseling', [$this->counselingRow()]);

        $result = $this->import('individual_counseling', [$this->counselingRow([
            __('fields.date') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(2, IndividualCounseling::where('mother_id_number', '123456789')->count());
    }

    public function test_the_same_mother_on_the_same_counseling_date_is_a_duplicate(): void
    {
        $this->import('individual_counseling', [$this->counselingRow()]);

        $result = $this->import('individual_counseling', [$this->counselingRow()]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_counseling'));
        $this->assertSame(1, IndividualCounseling::count());
    }

    // =================================================================
    // C2.5 Group Sessions
    // =================================================================

    private function groupSessionRow(array $cells = []): array
    {
        return $this->row('group_sessions', [
            __('fields.session_date') => '2026-08-20',
            __('fields.session_group_number') => '1',
            __('fields.session_subject') => 'bf_support',
            __('fields.locality') => 'tal_al_hawa',
            __('fields.shelter_name') => 'mosaab_camp',
            __('fields.id_number') => '123456789',
            __('fields.full_name_ar') => 'مشاركة الاستيراد',
            __('fields.category') => 'grandmothers',
            __('fields.marital_status') => 'married',
        ], $cells);
    }

    public function test_a_first_group_session_is_new_and_a_later_one_is_a_follow_up(): void
    {
        $this->assertImportedCleanly($this->import('group_sessions', [$this->groupSessionRow()]), 1);

        $this->assertSame('new', GroupSession::first()->visit_type);

        $result = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.session_date') => '2026-09-20',
        ])]);

        $this->assertImportedCleanly($result, 1);

        $later = GroupSession::orderByDesc('session_date')->first();
        $this->assertSame('follow_up', $later->visit_type);
    }

    public function test_the_same_participant_same_date_and_same_subject_is_a_duplicate(): void
    {
        $this->import('group_sessions', [$this->groupSessionRow()]);

        $result = $this->import('group_sessions', [$this->groupSessionRow()]);

        $this->assertSame(0, $result['imported']);
        $this->assertSkippedAsDuplicate($result, 2, __('fields.import_duplicate_session'));
        $this->assertSame(1, GroupSession::count());
    }

    /**
     * The subject is part of the key, so one mother may attend two different
     * sessions on one day.
     */
    public function test_a_different_subject_on_the_same_day_is_a_different_session(): void
    {
        $this->import('group_sessions', [$this->groupSessionRow()]);

        $result = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.session_subject') => 'Complementary Feeding',
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(2, GroupSession::count());
    }

    // =================================================================
    // C2.6 Follow-Up Children
    // =================================================================

    private function followUpRow(array $cells = []): array
    {
        return $this->row('follow_up_children', [
            __('fields.id_number') => '123456789',
            __('fields.child_name') => 'طفل المتابعة',
            __('fields.admission_date') => '2026-06-01',
            __('fields.discharge_outcome') => 'تحت المتابعة',
        ], $cells);
    }

    /**
     * Visits are numbered by the column they arrive in, and a missed visit
     * keeps its place. A child who attended 1, missed 2 and 3 and attended 4
     * has four visits numbered 1 to 4 - not two numbered 1 and 2.
     */
    public function test_missed_visits_keep_their_place_in_the_sequence(): void
    {
        // The status columns are written by the export, not by the template,
        // so a sheet that carries them carries them past the last template
        // column - exactly as a re-uploaded export does.
        $headings = $this->headings('follow_up_children');

        foreach ([2, 3] as $n) {
            $headings[] = __('fields.visit_status_n', ['n' => $n]);
        }

        $row = array_fill(0, count($headings), null);

        $cells = [
            __('fields.id_number') => '123456789',
            __('fields.child_name') => 'طفل المتابعة',
            __('fields.admission_date') => '2026-06-01',
            __('fields.discharge_outcome') => 'تحت المتابعة',
            __('fields.visit_date_n', ['n' => 1]) => '2026-06-01',
            __('fields.visit_muac_n', ['n' => 1]) => 110,
            __('fields.visit_date_n', ['n' => 2]) => '2026-06-08',
            __('fields.visit_status_n', ['n' => 2]) => __('fields.visit_missed'),
            __('fields.visit_date_n', ['n' => 3]) => '2026-06-15',
            __('fields.visit_status_n', ['n' => 3]) => __('fields.visit_missed'),
            __('fields.visit_date_n', ['n' => 4]) => '2026-06-22',
            __('fields.visit_muac_n', ['n' => 4]) => 118,
        ];

        foreach ($cells as $heading => $value) {
            $index = array_search($heading, $headings, true);
            $this->assertNotFalse($index, "Heading [{$heading}] is not in the sheet.");
            $row[$index] = $value;
        }

        $export = new class([$headings, $row]) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'follow-up-sequence-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        $result = app(ExcelImportService::class)->import(
            ImportDefinition::get('follow_up_children'),
            \Storage::disk('local')->path($name),
        );

        $this->assertImportedCleanly($result, 1);

        $visits = FollowUpChild::with('visits')->first()->visits;

        $this->assertSame([1, 2, 3, 4], $visits->pluck('visit_number')->all());
        $this->assertSame(
            ['attended', 'missed', 'missed', 'attended'],
            $visits->pluck('status')->all(),
        );
    }

    public function test_the_same_child_visit_number_and_visit_date_is_a_duplicate(): void
    {
        $this->import('follow_up_children', [$this->followUpRow([
            __('fields.visit_date_n', ['n' => 1]) => '2026-06-01',
            __('fields.visit_muac_n', ['n' => 1]) => 110,
        ])]);

        $result = $this->import('follow_up_children', [$this->followUpRow([
            __('fields.visit_date_n', ['n' => 1]) => '2026-06-01',
            __('fields.visit_muac_n', ['n' => 1]) => 110,
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('2026-06-01', $result['skipped'][0]);
        $this->assertSame(1, FollowUpChild::where('id_number', '123456789')->count());
    }

    /**
     * A later visit of the same child is a different visit, and imports. The
     * duplicate key is the visit, not the child.
     */
    public function test_a_later_sequential_visit_for_the_same_child_still_imports(): void
    {
        // The first episode is closed: a child with an OPEN episode may not
        // be given a second one by an upload (F12), which is a different
        // rule from this one and is tested in FollowUpImportRulesTest.
        $this->import('follow_up_children', [$this->followUpRow([
            __('fields.discharge_outcome') => __('fields.defaulted'),
            __('fields.discharge_date') => '2026-06-20',
            __('fields.visit_date_n', ['n' => 1]) => '2026-06-01',
            __('fields.visit_muac_n', ['n' => 1]) => 110,
        ])]);

        $result = $this->import('follow_up_children', [$this->followUpRow([
            __('fields.admission_date') => '2026-07-01',
            __('fields.visit_date_n', ['n' => 1]) => '2026-07-01',
            __('fields.visit_muac_n', ['n' => 1]) => 112,
            __('fields.visit_date_n', ['n' => 2]) => '2026-07-08',
            __('fields.visit_muac_n', ['n' => 2]) => 118,
        ])]);

        $this->assertImportedCleanly($result, 1);
        $this->assertSame(2, FollowUpChild::where('id_number', '123456789')->count());
    }
}
