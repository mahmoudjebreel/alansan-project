<?php

namespace Tests\Feature;

use App\Exports\FollowUpChildrenExport;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\MealReportService;
use App\Support\ChildFollowUpTransfer;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One classification for every episode, the same wherever it is read.
 *
 *   New                         a first admission; a return after a
 *                               non-response; a return after a cure that was
 *                               not a SAM/MAM episode; nothing to follow
 *   Readmission after Relapse   every return at SAM or MAM after a SAM or MAM
 *                               episode closed as cured - from the first one,
 *                               as often as it happens, either programme
 *   Readmission after Defaulted a return after a default - as often as it happens
 *   Readmission after Other     a return after an eligible other exit - as often
 *
 * There is no separate Relapse classification. MEAL counts a readmission
 * after relapse under Relapse admission, and the other two under Readmission.
 *
 * The model, the listing column and both its filters, the export and the MEAL
 * report are all checked against the same expectation, for episodes opened
 * by the real workflow (linked) and for historical rows with no link.
 */
class UnifiedAdmissionClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // =================================================================
    // A-C. Every return after a cure is a readmission after relapse
    // =================================================================

    public function test_a_sam_cured_sam_is_a_readmission_after_relapse(): void
    {
        $first = $this->screenAndRefer('470000001', '2026-01-05', 110);
        $this->close($first, 'cured', '2026-02-01');

        $second = $this->screenAndRefer('470000001', '2026-03-01', 110);

        $this->assertClassified($first, null);
        $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertSame($first->getKey(), $second->previous_follow_up_child_id);
    }

    public function test_b_a_second_return_after_a_cure_is_again_a_readmission_after_relapse(): void
    {
        [$first, $second, $third] = $this->relapseChain('470000002', 3);

        $this->assertClassified($first, null);
        $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($third, FollowUpChild::READMISSION_AFTER_RELAPSE);
    }

    public function test_c_every_later_return_after_a_cure_stays_a_readmission_after_relapse(): void
    {
        [$first, $second, $third, $fourth] = $this->relapseChain('470000003', 4);

        $this->assertClassified($first, null);
        $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($third, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($fourth, FollowUpChild::READMISSION_AFTER_RELAPSE);

        // Every return after a cure, and nothing else.
        $this->assertSame(3, $this->countClassified('470000003', FollowUpChild::READMISSION_AFTER_RELAPSE));
    }

    public function test_a_default_in_between_changes_nothing_about_the_next_return_after_a_cure(): void
    {
        // SAM -> Cured -> SAM (after relapse) -> Defaulted -> back (after
        // defaulted) -> Cured -> SAM (after relapse): the episode followed
        // decides, every time.
        $first = $this->screenAndRefer('470000004', '2026-01-05', 110);
        $this->close($first, 'cured', '2026-02-01');

        $relapse = $this->screenAndRefer('470000004', '2026-03-01', 110);
        $this->close($relapse, 'defaulted', '2026-03-20');

        $afterDefault = $this->screenAndRefer('470000004', '2026-04-01', 112);
        $this->close($afterDefault, 'cured', '2026-05-01');

        $again = $this->screenAndRefer('470000004', '2026-06-01', 110);

        $this->assertClassified($relapse, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($afterDefault, FollowUpChild::READMISSION_AFTER_DEFAULTED);
        $this->assertClassified($again, FollowUpChild::READMISSION_AFTER_RELAPSE);
    }

    public function test_sam_and_mam_count_the_same_on_either_side_of_the_cure(): void
    {
        // [before the cure, after it]: SAM->SAM, SAM->MAM, MAM->MAM, MAM->SAM.
        $pairs = [[110, 110], [110, 118], [118, 118], [118, 110]];

        foreach ($pairs as $index => [$before, $after]) {
            $idNumber = '47000005' . $index;

            $first = $this->screenAndRefer($idNumber, '2026-01-05', $before);
            $this->close($first, 'cured', '2026-02-01');

            $second = $this->screenAndRefer($idNumber, '2026-03-01', $after);

            $this->assertSame($before === 110 ? 'SAM' : 'MAM', $first->admitted_with);
            $this->assertSame($after === 110 ? 'SAM' : 'MAM', $second->admitted_with);
            $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_RELAPSE, "{$first->admitted_with} -> {$second->admitted_with}");
            $this->assertSame(FollowUpChild::ADMISSION_READMISSION, $second->fresh()->derivedAdmissionType());
        }
    }

    // =================================================================
    // D-G. Readmissions after a default or an other exit repeat
    // =================================================================

    public function test_d_e_readmission_after_defaulted_repeats_without_limit(): void
    {
        $first = $this->screenAndRefer('470000010', '2026-01-05', 110);
        $this->close($first, 'defaulted', '2026-01-25');

        $second = $this->screenAndRefer('470000010', '2026-02-01', 110);
        $this->close($second, 'defaulted', '2026-02-20');

        $third = $this->screenAndRefer('470000010', '2026-03-01', 110);

        $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_DEFAULTED);
        $this->assertClassified($third, FollowUpChild::READMISSION_AFTER_DEFAULTED);
    }

    public function test_f_g_readmission_after_other_repeats_without_limit_for_every_other_exit(): void
    {
        foreach (FollowUpChild::OTHER_READMISSION_OUTCOMES as $index => $outcome) {
            $idNumber = '47000002' . $index;

            $first = $this->screenAndRefer($idNumber, '2026-01-05', 110);
            $this->close($first, $outcome, '2026-01-25');

            $second = $this->screenAndRefer($idNumber, '2026-02-01', 110);
            $this->close($second, $outcome, '2026-02-20');

            $third = $this->screenAndRefer($idNumber, '2026-03-01', 110);

            $this->assertClassified($second, FollowUpChild::READMISSION_AFTER_OTHER, $outcome);
            $this->assertClassified($third, FollowUpChild::READMISSION_AFTER_OTHER, $outcome);
        }
    }

    // =================================================================
    // H. New after a non-response, and after a cure that was not SAM/MAM
    // =================================================================

    public function test_h_a_return_after_non_responded_is_new(): void
    {
        $first = $this->screenAndRefer('470000030', '2026-01-05', 110);
        $this->close($first, 'non_responded', '2026-03-01');

        $second = $this->screenAndRefer('470000030', '2026-04-01', 110);

        $this->assertNull($second->previous_follow_up_child_id);
        $this->assertClassified($second, null);
    }

    public function test_a_return_after_a_cure_with_no_sam_mam_admission_is_new(): void
    {
        $this->legacy('470000031', ['admitted_with' => null, 'admission_date' => '2026-01-05', 'discharge_date' => '2026-02-01', 'discharge_outcome' => 'cured']);

        $second = $this->screenAndRefer('470000031', '2026-03-01', 110);

        $this->assertClassified($second, null);
    }

    public function test_a_cured_sam_episode_followed_by_a_normal_return_is_new(): void
    {
        $first = $this->legacy('470000032', ['admission_date' => '2026-01-05', 'discharge_date' => '2026-02-01', 'discharge_outcome' => 'cured']);
        $normal = $this->legacy('470000032', [
            'admitted_with' => null, 'admission_date' => '2026-03-01',
            'previous_follow_up_child_id' => $first->getKey(),
        ]);

        $this->assertClassified($normal, null);
    }

    // =================================================================
    // Historical rows with no link read the same history
    // =================================================================

    public function test_unlinked_historical_episodes_follow_the_same_rules(): void
    {
        $this->legacy('470000040', ['admission_date' => '2026-01-05', 'discharge_date' => '2026-02-01', 'discharge_outcome' => 'cured']);
        $relapse = $this->legacy('470000040', ['admission_date' => '2026-03-01', 'discharge_date' => '2026-04-01', 'discharge_outcome' => 'cured']);
        $again = $this->legacy('470000040', ['admission_date' => '2026-05-01', 'discharge_date' => '2026-05-20', 'discharge_outcome' => 'defaulted']);
        $afterDefault = $this->legacy('470000040', ['admission_date' => '2026-06-01']);

        $this->assertClassified($relapse, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($again, FollowUpChild::READMISSION_AFTER_RELAPSE);
        $this->assertClassified($afterDefault, FollowUpChild::READMISSION_AFTER_DEFAULTED);
    }

    public function test_a_stored_admission_type_never_decides_the_classification(): void
    {
        // Imported as a readmission with nothing before it: a new admission.
        $alone = $this->legacy('470000050', ['admission_date' => '2026-03-01', 'admission_type' => FollowUpChild::ADMISSION_READMISSION]);

        // Imported as new after a default: a readmission after defaulted.
        $this->legacy('470000051', ['admission_date' => '2026-01-05', 'discharge_date' => '2026-01-25', 'discharge_outcome' => 'defaulted']);
        $afterDefault = $this->legacy('470000051', ['admission_date' => '2026-03-01', 'admission_type' => FollowUpChild::ADMISSION_NEW]);

        $this->assertClassified($alone, null);
        $this->assertSame(FollowUpChild::ADMISSION_NEW, $alone->fresh()->derivedAdmissionType());
        $this->assertClassified($afterDefault, FollowUpChild::READMISSION_AFTER_DEFAULTED);
        $this->assertSame(FollowUpChild::ADMISSION_READMISSION, $afterDefault->fresh()->derivedAdmissionType());
    }

    // =================================================================
    // L-N. One source: model, listing, filters, export and MEAL agree
    // =================================================================

    public function test_l_m_n_every_reader_agrees_and_meal_counts_each_episode_once(): void
    {
        Carbon::setTestNow('2026-09-30');

        // A full history for one child, every episode admitted in 2026.
        [$first, $relapse, $readmissionAfterRelapse] = $this->relapseChain('470000060', 3);
        $this->close($readmissionAfterRelapse, 'defaulted', '2026-06-20');
        $afterDefault = $this->screenAndRefer('470000060', '2026-07-01', 110);

        $this->close($afterDefault, 'discharge_to_other', '2026-07-20');
        $afterOther = $this->screenAndRefer('470000060', '2026-08-01', 112);

        // A non-response returns as new, and an unlinked historical row.
        $nonResponded = $this->screenAndRefer('470000061', '2026-01-10', 110);
        $this->close($nonResponded, 'non_responded', '2026-03-01');
        $newAgain = $this->screenAndRefer('470000061', '2026-04-10', 110);
        $imported = $this->legacy('470000062', ['admission_date' => '2026-05-10', 'admission_type' => FollowUpChild::ADMISSION_READMISSION]);

        $expected = [
            [$first, null],
            [$relapse, FollowUpChild::READMISSION_AFTER_RELAPSE],
            [$readmissionAfterRelapse, FollowUpChild::READMISSION_AFTER_RELAPSE],
            [$afterDefault, FollowUpChild::READMISSION_AFTER_DEFAULTED],
            [$afterOther, FollowUpChild::READMISSION_AFTER_OTHER],
            [$nonResponded, null],
            [$newAgain, null],
            [$imported, null],
        ];

        // L/M: the model and the export row say the same, episode by episode.
        [$headings, $rows] = $this->exportCsv();

        foreach ($expected as [$episode, $classification]) {
            $this->assertClassified($episode, $classification);

            $row = $this->exportRow($headings, $rows, $episode);

            $this->assertSame(
                (string) FollowUpChildResource::readmissionClassificationLabel($classification),
                $row[array_search(__('fields.readmission_classification'), $headings, true)],
                "Export classification of episode {$episode->getKey()}.",
            );
            $this->assertSame(
                __('fields.' . (in_array($classification, FollowUpChild::READMISSION_KINDS, true) ? 'readmission' : 'new')),
                $row[array_search(__('fields.admission_type'), $headings, true)],
                "Export admission type of episode {$episode->getKey()}.",
            );
        }

        // M: the listing column and both filters select exactly those rows.
        $list = Livewire::test(ListFollowUpChildren::class);

        foreach ($expected as [$episode, $classification]) {
            $list->assertTableColumnStateSet(
                'case_classification',
                FollowUpChildResource::caseClassificationLabel($episode->fresh()),
                $episode,
            );
        }

        foreach (FollowUpChild::READMISSION_CLASSIFICATIONS as $classification) {
            $in = collect($expected)->filter(fn (array $pair): bool => $pair[1] === $classification)->map(fn (array $pair) => $pair[0]);
            $out = collect($expected)->reject(fn (array $pair): bool => $pair[1] === $classification)->map(fn (array $pair) => $pair[0]);

            Livewire::test(ListFollowUpChildren::class)
                ->filterTable('case_classification', $classification)
                ->assertCanSeeTableRecords($in->all())
                ->assertCanNotSeeTableRecords($out->all());
        }

        $readmissions = collect($expected)->filter(fn (array $pair): bool => in_array($pair[1], FollowUpChild::READMISSION_KINDS, true))->map(fn (array $pair) => $pair[0]);
        $news = collect($expected)->reject(fn (array $pair): bool => in_array($pair[1], FollowUpChild::READMISSION_KINDS, true))->map(fn (array $pair) => $pair[0]);

        Livewire::test(ListFollowUpChildren::class)
            ->filterTable('admission_type', FollowUpChild::ADMISSION_READMISSION)
            ->assertCanSeeTableRecords($readmissions->all())
            ->assertCanNotSeeTableRecords($news->all());

        Livewire::test(ListFollowUpChildren::class)
            ->filterTable('admission_type', FollowUpChild::ADMISSION_NEW)
            ->assertCanSeeTableRecords($news->all())
            ->assertCanNotSeeTableRecords($readmissions->all());

        // L/N: MEAL counts each episode once, in the column the model names.
        $totals = app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(2026, 1, 9), null)[MealReportLayout::SHEET_CMAM]['totals'];

        $byCategory = ['new' => 0, 'relapse' => 0, 'readmission' => 0];

        foreach ($expected as [$episode, $classification]) {
            $byCategory[FollowUpChild::admissionCategoryOf($classification)]++;
        }

        $this->assertSame(['new' => 4, 'relapse' => 2, 'readmission' => 2], $byCategory);
        $this->assertSame($byCategory['new'], $this->sumAdmissions($totals, 'new'));
        $this->assertSame($byCategory['relapse'], $this->sumAdmissions($totals, 'relapse'));
        $this->assertSame($byCategory['readmission'], $this->sumAdmissions($totals, 'readmission'));
        $this->assertSame(count($expected), $this->sumAdmissions($totals, null), 'Every episode counted exactly once.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * A child screened on a date and referred through the real transfer.
     */
    private function screenAndRefer(string $idNumber, string $date, int $muac): FollowUpChild
    {
        $child = Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $idNumber,
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => $muac,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
        ]);

        $episode = ChildFollowUpTransfer::refer($child);

        $this->assertNotNull($episode, "Referral of {$idNumber} on {$date} opened nothing.");
        $this->assertSame($date, $episode->admission_date->format('Y-m-d'));

        return $episode->fresh();
    }

    private function close(FollowUpChild $episode, string $outcome, string $date): void
    {
        $episode->update(['discharge_outcome' => $outcome, 'discharge_date' => $date]);
    }

    /**
     * A chain of SAM episodes for one child, each closed as cured before the
     * next is referred; the last is left open.
     *
     * @return list<FollowUpChild>
     */
    private function relapseChain(string $idNumber, int $length): array
    {
        $episodes = [];

        foreach (range(1, $length) as $i) {
            $episode = $this->screenAndRefer($idNumber, sprintf('2026-%02d-01', $i * 2 - 1), 110);

            if ($i < $length) {
                $this->close($episode, 'cured', sprintf('2026-%02d-15', $i * 2));
            }

            $episodes[] = $episode;
        }

        return $episodes;
    }

    /** A historical row written with no link, as an import writes one. */
    private function legacy(string $idNumber, array $attributes): FollowUpChild
    {
        return FollowUpChild::create(array_merge([
            'id_number' => $idNumber,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ], $attributes));
    }

    /**
     * The classification read three ways - the single-record method, the
     * scope a listing page selects, and the prospective reading the Children
     * form uses before the episode exists - must be one answer.
     */
    private function assertClassified(FollowUpChild $episode, ?string $expected, string $context = ''): void
    {
        $fresh = FollowUpChild::withTrashed()->findOrFail($episode->getKey());

        $this->assertSame($expected, $fresh->readmissionClassification(), "Model, episode {$episode->getKey()} {$context}");

        $scoped = FollowUpChild::withTrashed()->whereKey($episode->getKey())->withAdmissionClassification()->first();
        $this->assertSame($expected, $scoped->readmissionClassification(), "Scope, episode {$episode->getKey()} {$context}");
    }

    private function countClassified(string $idNumber, string $classification): int
    {
        return FollowUpChild::query()
            ->where('id_number', $idNumber)
            ->withAdmissionClassification()
            ->get()
            ->filter(fn (FollowUpChild $episode): bool => $episode->readmissionClassification() === $classification)
            ->count();
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, string>>} */
    private function exportCsv(): array
    {
        $handle = fopen('php://memory', 'w+');
        (new FollowUpChildrenExport(FollowUpChild::query()))->writeCsv($handle);
        rewind($handle);
        $content = substr((string) stream_get_contents($handle), 3);
        fclose($handle);

        $lines = array_values(array_filter(explode("\r\n", $content), fn (string $line): bool => $line !== ''));
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', '\\'), $lines);

        return [array_shift($rows), $rows];
    }

    private function exportRow(array $headings, array $rows, FollowUpChild $episode): array
    {
        $id = array_search(__('fields.id_number'), $headings, true);
        $admitted = array_search(__('fields.admission_date'), $headings, true);

        foreach ($rows as $row) {
            if ($row[$id] === $episode->id_number && $row[$admitted] === $episode->admission_date->format('Y-m-d')) {
                return $row;
            }
        }

        $this->fail("No exported row for episode {$episode->getKey()}.");
    }

    /** Admissions in the CMAM totals, in one admission column or all of them. */
    private function sumAdmissions(array $totals, ?string $kind): int
    {
        $sum = 0;

        foreach ($totals as $key => $value) {
            if (! str_contains($key, '_adm_') || ! is_numeric($value)) {
                continue;
            }

            if ($kind === null || str_contains($key, "_{$kind}_")) {
                $sum += (int) $value;
            }
        }

        return $sum;
    }
}
