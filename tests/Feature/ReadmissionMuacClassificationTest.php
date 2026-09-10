<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use App\Support\Referral\CuredChildrenReferral;
use App\Support\Referral\ReferralCandidates;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reading entered on a readmission classifies the readmission.
 *
 * SAM, MAM or Normal, by the one shared classifier and never by copying the
 * previous episode's class. A SAM or MAM reading admits the child with that
 * class, as before. A Normal reading opens the episode too: the child is
 * back under monitoring, admitted with neither, and nothing is written to
 * Children until a person closes the episode as cured and refers by hand.
 */
class ReadmissionMuacClassificationTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '470828468';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * A closed SAM episode the child may be readmitted after. The old class
     * is SAM on purpose: the new one must come from the new reading.
     */
    private function closedSamEpisode(): FollowUpChild
    {
        $record = FollowUpChild::factory()->create([
            'id_number' => self::CHILD_ID,
            'child_name' => 'طفل الاختبار',
            'sex' => 'F',
            'dob' => '2025-02-10',
            'mobile_number' => '0591234567',
            'shelter_name' => 'مركز الإيواء أ',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-08-19',
            'discharge_date' => '2026-08-26',
            'discharge_outcome' => 'discharge_to_opt',
        ]);

        $record->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => 112, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function readmissionData(float|int $muac): array
    {
        return [
            'admission_date' => '2026-09-09',
            'visit_date' => '2026-09-09',
            'muac' => $muac,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        return [
            'record' => $record->fresh()->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    /**
     * Readmit through the action on the record page, and return the new
     * episode. The old one is asserted untouched every time.
     */
    private function readmit(FollowUpChild $previous, float|int $muac): FollowUpChild
    {
        $before = $this->snapshot($previous);

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->callAction('readmission', data: $this->readmissionData($muac))
            ->assertHasNoActionErrors()
            ->assertNotified(__('ui.readmission.done_title'));

        $this->assertSame($before, $this->snapshot($previous));

        $new = FollowUpChild::query()->where('id_number', self::CHILD_ID)->orderByDesc('id')->first();

        $this->assertNotSame($previous->getKey(), $new->getKey());
        $this->assertTrue($new->isReadmission());
        $this->assertSame($previous->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertFalse($new->isLocked());

        // Visit 1 of the new episode carries the reading entered.
        $visits = $new->visits()->get();
        $this->assertCount(1, $visits);
        $this->assertSame(1, $visits->first()->visit_number);
        $this->assertEqualsWithDelta((float) $muac, (float) $visits->first()->muac, 0.001);

        return $new;
    }

    /** TEST 1 */
    public function test_a_sam_reading_readmits_the_child_as_sam(): void
    {
        $new = $this->readmit($this->closedSamEpisode(), 110);

        $this->assertSame(MuacClassifier::SAM, $new->admitted_with);
        $this->assertSame(MuacClassifier::SAM, $new->visits()->first()->fi);

        // Under follow-up like any SAM admission: open, active, counted.
        $this->assertTrue(ChildFollowUpTransfer::hasOpenEpisode(self::CHILD_ID));
        $this->assertSame(1, ReferralCandidates::activeFollowUps()->count());
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanSeeTableRecords([$new]);
    }

    /** TEST 2 */
    public function test_a_mam_reading_readmits_the_child_as_mam(): void
    {
        // The old episode was SAM; the new reading says MAM, and MAM wins.
        $new = $this->readmit($this->closedSamEpisode(), 118);

        $this->assertSame(MuacClassifier::MAM, $new->admitted_with);
        $this->assertSame(MuacClassifier::MAM, $new->visits()->first()->fi);

        $this->assertTrue(ChildFollowUpTransfer::hasOpenEpisode(self::CHILD_ID));
        $this->assertSame(1, ReferralCandidates::activeFollowUps()->count());
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanSeeTableRecords([$new]);
    }

    /** TEST 3 */
    public function test_a_normal_reading_readmits_the_child_as_normal_under_monitoring(): void
    {
        $new = $this->readmit($this->closedSamEpisode(), 125);

        // Normal: admitted with neither SAM nor MAM, and visit 1 says so.
        $this->assertNull($new->admitted_with);
        $this->assertSame(MuacClassifier::NORMAL, $new->visits()->first()->fi);
        $this->assertNotSame(MuacClassifier::SAM, $new->admitted_with);
        $this->assertNotSame(MuacClassifier::MAM, $new->admitted_with);

        // Still under follow-up: open, active, listed with the active cases.
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertTrue(ChildFollowUpTransfer::hasOpenEpisode(self::CHILD_ID));
        $this->assertSame(1, ReferralCandidates::activeFollowUps()->count());
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanSeeTableRecords([$new]);
    }

    /**
     * One source of truth: the boundaries are the classifier's, and 125 mm
     * sits on the Normal side of them.
     */
    public function test_the_readmission_uses_the_shared_muac_thresholds(): void
    {
        $this->assertSame(115, MuacClassifier::SAM_MAX_MM);
        $this->assertSame(125, MuacClassifier::MAM_MAX_MM);

        foreach ([115 => 'SAM', 116 => 'MAM', 124.9 => 'MAM', 125 => null, 130 => null] as $muac => $expected) {
            $previous = FollowUpChild::factory()->create([
                'id_number' => (string) (480000000 + (int) ($muac * 10)),
                'sex' => 'M',
                'admitted_with' => 'SAM',
                'discharge_date' => '2026-08-26',
                'discharge_outcome' => 'discharge_to_opt',
            ]);

            $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData($muac));

            $this->assertNotNull($new, "[{$muac}] must open a readmission.");
            $this->assertSame($expected, $new->admitted_with, "[{$muac}] admission classification.");
            $this->assertSame(MuacClassifier::classify($muac), $new->visits()->first()->fi, "[{$muac}] visit 1 FI.");
        }
    }

    public function test_a_reading_that_classifies_as_nothing_still_opens_no_readmission(): void
    {
        $previous = $this->closedSamEpisode();

        $blank = array_merge($this->readmissionData(0), ['muac' => null]);

        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($previous, $blank));
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($previous, ['muac' => '']));

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->callAction('readmission', data: $blank)
            ->assertHasActionErrors(['muac']);

        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    /** TEST 4 */
    public function test_a_normal_readmission_writes_nothing_to_children(): void
    {
        $this->assertSame(0, Child::count());

        $new = $this->readmit($this->closedSamEpisode(), 125);

        $this->assertSame(0, Child::count());
        $this->assertSame(0, Child::query()->where('child_id', self::CHILD_ID)->count());

        // Not cured, so not waiting for a referral either.
        $this->assertFalse(CuredChildrenReferral::isPending($new));
        $this->assertSame(0, CuredChildrenReferral::query()->count());
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('referToChildren', $new);
    }

    /** TEST 5 */
    public function test_once_the_normal_readmission_is_closed_as_cured_the_manual_referral_is_offered(): void
    {
        $previous = $this->closedSamEpisode();
        $new = $this->readmit($previous, 125);
        $previousBefore = $this->snapshot($previous);

        // A person closes the episode as cured on the record itself. The
        // record was admitted with neither SAM nor MAM, and saves as such.
        Livewire::test(EditFollowUpChild::class, ['record' => $new->getKey()])
            ->fillForm([
                'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
                'discharge_date' => '2026-09-09',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $new->refresh();
        $this->assertSame(FollowUpChild::CURED_OUTCOME, $new->discharge_outcome);
        $this->assertTrue($new->isLocked());
        $this->assertNull($new->admitted_with);

        // Closing as cured wrote nothing to Children: the referral is manual.
        $this->assertSame(0, Child::count());
        $this->assertTrue(CuredChildrenReferral::isPending($new));

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'cured_pending_referral'])
            ->assertCanSeeTableRecords([$new])
            ->assertTableActionVisible('referToChildren', $new)
            ->callTableAction('referToChildren', $new)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('ui.cured_referral.done_title'));

        $child = Child::query()->where('child_id', self::CHILD_ID)->sole();
        $this->assertSame($new->getKey(), $child->source_follow_up_child_id);
        $this->assertEqualsWithDelta(125.0, (float) $child->muac_mm, 0.001);
        $this->assertSame(MuacClassifier::NORMAL, $child->fi);

        // The old episode: still closed, not a byte moved.
        $this->assertSame($previousBefore, $this->snapshot($previous));
    }

    /** TEST 6 */
    public function test_the_old_episode_is_left_exactly_as_it_was_whatever_the_new_reading(): void
    {
        $index = 0;

        foreach ([110, 118, 125] as $muac) {
            $previous = FollowUpChild::factory()->create([
                'id_number' => (string) (490000000 + $index++),
                'child_name' => 'طفل الاختبار',
                'sex' => 'M',
                'dob' => '2025-02-10',
                'admitted_with' => 'SAM',
                'admission_date' => '2026-08-19',
                'discharge_date' => '2026-08-26',
                'discharge_outcome' => 'referred_medical_inpt',
            ]);
            $previous->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110]);
            $previous->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => null, 'status' => FollowUpChildVisit::STATUS_MISSED]);

            $before = $this->snapshot($previous);

            $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData($muac));

            $this->assertNotNull($new);
            $this->assertSame($before, $this->snapshot($previous), "[{$muac}] the old episode moved.");
            $this->assertSame('referred_medical_inpt', $previous->fresh()->discharge_outcome);
            $this->assertSame('SAM', $previous->fresh()->admitted_with);
            $this->assertSame(2, $previous->visits()->count());
            $this->assertSame(2, FollowUpChild::where('id_number', $previous->id_number)->count());
        }
    }
}
