<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\Referral\ReferralCandidates;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Which closed episodes allow a readmission.
 *
 * The rule is the discharge outcome of the closed episode and nothing else.
 * Three outcomes allow one - discharge to OTP, discharge to other, referred
 * for a medical reason - and every other closed outcome does not, however
 * closed the record is. Being closed is never the test.
 *
 * The rest of the feature is covered by FollowUpReadmissionAndVisitHistoryTest;
 * this file defends the eligibility line and what a readmission leaves behind.
 */
class FollowUpReadmissionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '470828468';

    /** The three outcomes the rule allows, and nothing else. */
    private const ELIGIBLE = ['discharge_to_opt', 'discharge_to_other', 'referred_medical_inpt'];

    /** Closed outcomes that never allow a readmission. */
    private const INELIGIBLE = ['defaulted', 'cured', 'non_responded', 'died'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * A finished episode with the visit history the request describes: one
     * attended visit with a reading, one missed visit.
     */
    private function closedEpisode(?string $outcome, array $attributes = []): FollowUpChild
    {
        $record = FollowUpChild::factory()->create(array_merge([
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
            'discharge_outcome' => $outcome,
        ], $attributes));

        $record->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => null, 'status' => FollowUpChildVisit::STATUS_MISSED]);

        return $record;
    }

    private function openEpisode(array $attributes = []): FollowUpChild
    {
        return $this->closedEpisode(FollowUpChild::ACTIVE_OUTCOME, array_merge(['discharge_date' => null], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function readmissionData(): array
    {
        return [
            'admission_date' => '2026-09-09',
            'visit_date' => '2026-09-09',
            'muac' => 118,
        ];
    }

    /**
     * Everything about a record and its visits, as stored, so "unchanged"
     * is asserted literally rather than field by field.
     *
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        return [
            'record' => $record->fresh()->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    private function assertOffered(FollowUpChild $record): void
    {
        $this->assertTrue($record->canBeReadmitted(), "[{$record->discharge_outcome}] must allow a readmission.");

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionVisible('readmission');

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionVisible('readmission');

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableActionVisible('readmission', $record);
    }

    private function assertNotOffered(FollowUpChild $record): void
    {
        $this->assertFalse($record->canBeReadmitted(), "[{$record->discharge_outcome}] must not allow a readmission.");

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionHidden('readmission');

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionHidden('readmission');

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableActionHidden('readmission', $record);

        // The transfer refuses too, so nothing can reach it around the button.
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($record, $this->readmissionData()));
        $this->assertSame(1, FollowUpChild::where('id_number', $record->id_number)->count());
    }

    // =================================================================
    // Eligibility: the outcome decides, not the closed state
    // =================================================================

    /** TEST 1 */
    public function test_readmission_is_offered_after_a_discharge_to_otp(): void
    {
        $this->assertOffered($this->closedEpisode('discharge_to_opt'));
    }

    /** TEST 2 */
    public function test_readmission_is_offered_after_a_discharge_to_other(): void
    {
        $this->assertOffered($this->closedEpisode('discharge_to_other'));
    }

    /** TEST 3 */
    public function test_readmission_is_offered_after_a_referral_for_medical_reason(): void
    {
        $this->assertOffered($this->closedEpisode('referred_medical_inpt'));
    }

    /** TEST 4 */
    public function test_readmission_is_not_offered_to_a_defaulter(): void
    {
        $this->assertNotOffered($this->closedEpisode('defaulted'));
    }

    /** TEST 5 */
    public function test_readmission_is_not_offered_to_a_cured_child(): void
    {
        $this->assertNotOffered($this->closedEpisode('cured'));
    }

    /** TEST 6 */
    public function test_readmission_is_not_offered_to_a_non_responded_child(): void
    {
        $this->assertNotOffered($this->closedEpisode('non_responded'));
    }

    /** TEST 7 */
    public function test_readmission_is_not_offered_to_a_child_who_died(): void
    {
        $this->assertNotOffered($this->closedEpisode('died'));
    }

    public function test_readmission_is_not_offered_while_the_episode_is_open(): void
    {
        $this->assertNotOffered($this->openEpisode());
        $this->assertNotOffered($this->closedEpisode(null, ['id_number' => '470828469', 'discharge_date' => null]));
    }

    /**
     * "Closed" is never the test: every closing outcome locks the record, and
     * only the three named ones allow a readmission.
     */
    public function test_eligibility_is_decided_by_the_outcome_and_never_by_being_closed(): void
    {
        $this->assertEqualsCanonicalizing(self::ELIGIBLE, FollowUpChild::READMISSION_OUTCOMES);

        foreach (FollowUpChild::CLOSING_OUTCOMES as $index => $outcome) {
            $record = $this->closedEpisode($outcome, ['id_number' => (string) (480000000 + $index)]);

            $this->assertTrue($record->isLocked(), "[{$outcome}] closes the episode.");
            $this->assertSame(
                in_array($outcome, self::ELIGIBLE, true),
                $record->isReadmissionEligible(),
                "[{$outcome}] eligibility must follow the outcome list.",
            );
            $this->assertSame($record->isReadmissionEligible(), $record->canBeReadmitted());
        }

        foreach (self::INELIGIBLE as $outcome) {
            $this->assertContains($outcome, FollowUpChild::CLOSING_OUTCOMES, "[{$outcome}] must still close the episode.");
            $this->assertNotContains($outcome, FollowUpChild::READMISSION_OUTCOMES);
        }
    }

    public function test_readmission_is_not_offered_again_once_the_new_episode_is_open(): void
    {
        $previous = $this->closedEpisode('discharge_to_opt');

        $this->assertNotNull(ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData()));

        // The old record still carries an eligible outcome, but the child is
        // already being followed up: a second open episode is never offered.
        $this->assertFalse($previous->fresh()->canBeReadmitted());

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->assertActionHidden('readmission');
    }

    // =================================================================
    // What a readmission writes, and what it leaves alone
    // =================================================================

    /** TEST 8 */
    public function test_readmission_opens_a_new_episode_from_visit_1_and_leaves_the_old_one_closed(): void
    {
        $previous = $this->closedEpisode('referred_medical_inpt');
        $before = $this->snapshot($previous);

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->callAction('readmission', data: $this->readmissionData())
            ->assertHasNoActionErrors();

        $episodes = FollowUpChild::query()->where('id_number', self::CHILD_ID)->orderBy('id')->get();
        $this->assertCount(2, $episodes);

        $new = $episodes->last();
        $this->assertNotSame($previous->getKey(), $new->getKey());

        // A fresh admission, open, starting from visit 1, named as what it is.
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertNull($new->discharge_date);
        $this->assertFalse($new->isLocked());
        $this->assertTrue($new->isReadmission());
        $this->assertSame($previous->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame('2026-09-09', $new->admission_date->format('Y-m-d'));
        $this->assertSame('MAM', $new->admitted_with);

        $visits = $new->visits()->get();
        $this->assertCount(1, $visits);
        $this->assertSame(1, $visits->first()->visit_number);
        $this->assertSame('2026-09-09', $visits->first()->visit_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(118.0, (float) $visits->first()->muac, 0.001);

        // The old episode: still closed, and not a byte of it moved.
        $this->assertSame($before, $this->snapshot($previous));
        $this->assertTrue($previous->fresh()->isLocked());
        $this->assertSame('referred_medical_inpt', $previous->fresh()->discharge_outcome);
        $this->assertSame('2026-08-26', $previous->fresh()->discharge_date->format('Y-m-d'));
    }

    /** TEST 9 */
    public function test_readmission_keeps_the_same_child_identity_and_creates_no_child_record(): void
    {
        // The screening that once admitted the child, in the Children module.
        Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 110, 'date_of_reporting' => '2026-08-19']);
        $childrenBefore = Child::count();

        $previous = $this->closedEpisode('discharge_to_opt');

        // Closed is not new: the child is recognised by id_number.
        $this->assertSame(
            ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED,
            ReferralCandidates::statusFor(Child::firstWhere('child_id', self::CHILD_ID)),
        );

        $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData());

        $this->assertSame(self::CHILD_ID, $new->id_number);
        $this->assertSame($previous->child_name, $new->child_name);
        $this->assertSame($previous->sex, $new->sex);
        $this->assertSame($previous->dob->format('Y-m-d'), $new->dob->format('Y-m-d'));

        // One child, two episodes; nothing written to Children.
        $this->assertSame($childrenBefore, Child::count());
        $this->assertSame(1, FollowUpChild::query()->distinct()->count('id_number'));
        $this->assertSame(2, FollowUpChild::where('id_number', self::CHILD_ID)->count());

        // And the Referral Centre now sees the same child as being followed up.
        $this->assertSame(
            ReferralCandidates::STATUS_IN_FOLLOW_UP,
            ReferralCandidates::statusFor(Child::firstWhere('child_id', self::CHILD_ID)),
        );
    }

    /** TEST 10 */
    public function test_the_attended_and_missed_history_of_the_old_episode_is_left_exactly_as_it_was(): void
    {
        $previous = $this->closedEpisode('discharge_to_other');
        $before = $this->snapshot($previous);

        $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData());

        // One attended, one missed - neither collapsed, invented or removed.
        $this->assertSame($before, $this->snapshot($previous));

        $history = $previous->visits()->get();
        $this->assertCount(2, $history);
        $this->assertSame(FollowUpChildVisit::STATUS_ATTENDED, $history[0]->status);
        $this->assertEqualsWithDelta(110.0, (float) $history[0]->muac, 0.001);
        $this->assertSame(FollowUpChildVisit::STATUS_MISSED, $history[1]->status);
        $this->assertNull($history[1]->muac);

        // The two histories are independent.
        $this->assertSame(3, FollowUpChildVisit::count());
        $this->assertSame(1, $new->visits()->count());
    }

    // =================================================================
    // Non Responded, and the option list
    // =================================================================

    public function test_non_responded_closes_the_episode_keeps_its_history_and_offers_no_readmission(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'discharge_outcome' => 'non_responded',
                'discharge_date' => '2026-08-26',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        $this->assertSame('non_responded', $record->discharge_outcome);
        $this->assertTrue($record->isLocked());
        $this->assertSame(1, ReferralCandidates::closedFollowUps()->count());
        $this->assertSame(0, ReferralCandidates::activeFollowUps()->count());

        // The visits are still there, and it is neither a defaulter nor a
        // medical referral, and not a readmission.
        $this->assertSame(2, $record->visits()->count());
        $this->assertNotSame('defaulted', $record->discharge_outcome);
        $this->assertNotSame('referred_medical_inpt', $record->discharge_outcome);
        $this->assertNotOffered($record);
    }

    /** TEST 11 */
    public function test_every_discharge_outcome_remains_available_on_the_edit_form(): void
    {
        $expected = [
            'cured',
            'defaulted',
            'discharge_to_opt',
            'discharge_to_other',
            'non_responded',
            'referred_medical_inpt',
            'died',
            'under_follow_up',
        ];

        $this->assertEqualsCanonicalizing($expected, array_keys(FollowUpChildResource::dischargeOutcomeOptions()));

        // The readmission list is a different thing from the list a person
        // may choose from: nothing is hidden on the form.
        foreach ([$this->openEpisode(), $this->closedEpisode('cured', ['id_number' => '470828471'])] as $record) {
            Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
                ->assertFormFieldExists(
                    'discharge_outcome',
                    fn (Select $field): bool => count(array_diff($expected, array_keys($field->getOptions()))) === 0,
                );
        }
    }

    // =================================================================
    // The other two doors: the Referral Centre and the Children screening
    // =================================================================

    public function test_the_referral_centre_offers_readmission_only_after_an_eligible_outcome(): void
    {
        $this->closedEpisode('discharge_to_opt');
        $this->closedEpisode('cured', ['id_number' => '470828472']);

        $eligible = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 110, 'date_of_reporting' => '2026-09-09']);
        $cured = Child::factory()->create(['child_id' => '470828472', 'muac_mm' => 110, 'date_of_reporting' => '2026-09-09']);

        // Both are the same child as before - closed is not new for either.
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($eligible));
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($cured));

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->assertTableActionVisible('readmit', $eligible)
            ->assertTableActionHidden('readmit', $cured);

        // And the transfer behind the button refuses the cured child outright.
        $this->assertNull(ChildFollowUpTransfer::readmit($cured));
        $this->assertSame(1, FollowUpChild::where('id_number', '470828472')->count());
    }

    public function test_a_screening_after_an_ineligible_closed_episode_is_a_first_admission_not_a_readmission(): void
    {
        $cured = $this->closedEpisode('cured');
        $before = $this->snapshot($cured);

        $child = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 110, 'date_of_reporting' => '2026-09-09']);

        $episode = ChildFollowUpTransfer::refer($child);

        $this->assertNotNull($episode);
        $this->assertFalse($episode->isReadmission());
        $this->assertNull($episode->previous_follow_up_child_id);
        $this->assertSame($before, $this->snapshot($cured));
    }

    public function test_a_screening_after_an_eligible_closed_episode_is_a_readmission(): void
    {
        $previous = $this->closedEpisode('discharge_to_other');

        $child = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 110, 'date_of_reporting' => '2026-09-09']);

        $episode = ChildFollowUpTransfer::refer($child);

        $this->assertTrue($episode->isReadmission());
        $this->assertSame($previous->getKey(), $episode->previous_follow_up_child_id);
    }

    public function test_the_follow_up_import_stores_the_two_outcomes_as_themselves(): void
    {
        $synonyms = ImportDefinition::get('follow_up_children')->synonyms['discharge_outcome'];

        $this->assertSame('non_responded', $synonyms['Non Responded']);
        $this->assertSame('referred_medical_inpt', $synonyms['Referred For Medical Reason(inpt)']);
        $this->assertSame('discharge_to_other', $synonyms['Discharge to Other']);
    }
}
