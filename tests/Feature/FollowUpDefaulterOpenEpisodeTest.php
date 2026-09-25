<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\ChildDuplicateChecker;
use App\Support\ChildFollowUpTransfer;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Defaulted is a closing outcome that allows a readmission.
 *
 * A defaulter has left the programme: the episode closes with the date it
 * closed on, the attended/missed history is kept visit by visit, and a child
 * who comes back is readmitted into a NEW episode that follows the closed
 * one - a "readmission after defaulted", starting at visit 1. A readmission
 * is allowed only after the four outcomes that expect the child back. Non
 * Responded closes and allows none.
 *
 * The child is the same child throughout, known by ID number, whether the
 * episode on file is open or closed.
 */
class FollowUpDefaulterOpenEpisodeTest extends TestCase
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

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * The case from the request: admitted 2026-08-19, two visits, closed
     * 2026-08-26 - with the outcome given, or open when none is.
     */
    private function episode(?string $outcome, array $attributes = []): FollowUpChild
    {
        $closed = $outcome !== null && $outcome !== FollowUpChild::ACTIVE_OUTCOME;

        $record = FollowUpChild::factory()->create(array_merge([
            'id_number' => self::CHILD_ID,
            'child_name' => 'محمد عبد الكريم خليل عليوة',
            'sex' => 'M',
            'dob' => '2025-01-15',
            'mobile_number' => '0591111111',
            'shelter_name' => 'مركز الإيواء أ',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-08-19',
            'discharge_date' => $closed ? '2026-08-26' : null,
            'discharge_outcome' => $outcome ?? FollowUpChild::ACTIVE_OUTCOME,
        ], $attributes));

        $record->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110]);
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => 112]);

        return $record->fresh();
    }

    private function openEpisode(array $attributes = []): FollowUpChild
    {
        return $this->episode(null, $attributes);
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
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        $record = $record->fresh();

        return [
            'record' => $record->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    private function assertReadmissionOffered(FollowUpChild $record): void
    {
        $this->assertTrue($record->canBeReadmitted(), "[{$record->discharge_outcome}] must allow a readmission.");

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionVisible('readmission');

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionVisible('readmission');

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableActionVisible('readmission', $record);
    }

    private function assertReadmissionNotOffered(FollowUpChild $record): void
    {
        $this->assertFalse($record->canBeReadmitted(), "[{$record->discharge_outcome}] must not allow a readmission.");

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionHidden('readmission');

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->assertActionHidden('readmission');

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableActionHidden('readmission', $record);

        // Nothing reaches the transfer around the button either.
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($record, $this->readmissionData()));
        $this->assertSame(1, FollowUpChild::where('id_number', $record->id_number)->count());
    }

    // =================================================================
    // TEST 1 - Defaulter closes the follow-up case
    // =================================================================

    public function test_defaulter_closes_the_follow_up_case(): void
    {
        $record = $this->openEpisode();

        // A closing outcome needs the date it closed on; nothing is invented.
        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['discharge_outcome' => 'defaulted', 'discharge_date' => null])
            ->call('save')
            ->assertHasFormErrors(['discharge_date']);

        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $record->fresh()->discharge_outcome);

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['discharge_outcome' => 'defaulted', 'discharge_date' => '2026-09-02'])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        // The outcome is recorded, and the case is closed.
        $this->assertSame('defaulted', $record->discharge_outcome);
        $this->assertSame('2026-09-02', $record->discharge_date->format('Y-m-d'));
        $this->assertTrue($record->isLocked());
        $this->assertContains('defaulted', FollowUpChild::CLOSING_OUTCOMES);
        $this->assertFalse(ChildFollowUpTransfer::hasOpenEpisode(self::CHILD_ID));
        $this->assertSame(0, ReferralCandidates::activeFollowUps()->count());
        $this->assertSame(1, ReferralCandidates::closedFollowUps()->count());

        // The existing visits are still there.
        $this->assertCount(2, $record->visits);
        $this->assertSame([1, 2], $record->visits->pluck('visit_number')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanNotSeeTableRecords([$record]);
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record]);

        // The closed case is history: a further save is refused, and the
        // child comes back through a readmission into a new case instead.
        $before = $this->snapshot($record);

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['notes' => 'came back'])
            ->call('save')
            ->assertNotified(__('fields.record_locked_notice'));

        $this->assertSame($before['record'], $this->snapshot($record)['record']);
        $this->assertSame(1, FollowUpChild::count());
        $this->assertReadmissionOffered($record->fresh());
    }

    // =================================================================
    // TEST 2 - Defaulter shows Readmission, classified after defaulted
    // =================================================================

    public function test_defaulter_shows_readmission(): void
    {
        $record = $this->episode('defaulted');

        $this->assertTrue($record->isLocked());
        $this->assertTrue($record->isReadmissionEligible());
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $record->classifiesReturnAs());
        $this->assertReadmissionOffered($record);
        $this->assertTrue($record->is(FollowUpChild::readmittableEpisodeFor(self::CHILD_ID)));

        // The readmission opens a new case at visit 1, linked to this one,
        // and this one is left exactly as it was. What the dialog says is
        // covered by ReadmissionClassificationTest.
        $before = $this->snapshot($record);

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->callAction('readmission', data: $this->readmissionData())
            ->assertHasNoActionErrors();

        $new = FollowUpChild::where('id_number', self::CHILD_ID)->whereKeyNot($record->getKey())->sole();

        $this->assertTrue($new->isReadmission());
        $this->assertSame($record->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $new->readmissionClassification());
        $this->assertSame([1], $new->visits->pluck('visit_number')->all());
        $this->assertSame($before, $this->snapshot($record));
    }

    // =================================================================
    // TEST 4 - Readmission creates a NEW episode starting at visit 1
    // =================================================================

    public function test_readmission_creates_a_new_follow_up_episode_starting_at_visit_1(): void
    {
        $previous = $this->episode('discharge_to_opt');

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->callAction('readmission', data: $this->readmissionData())
            ->assertHasNoActionErrors();

        $episodes = FollowUpChild::where('id_number', self::CHILD_ID)->orderBy('id')->get();
        $this->assertCount(2, $episodes);

        $new = $episodes->last();

        $this->assertNotSame($previous->getKey(), $new->getKey());
        $this->assertSame(self::CHILD_ID, $new->id_number);
        $this->assertSame($previous->child_name, $new->child_name);
        $this->assertTrue($new->isReadmission());
        $this->assertSame($previous->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertNull($new->discharge_date);
        $this->assertFalse($new->isLocked());
        $this->assertSame('2026-09-09', $new->admission_date->format('Y-m-d'));

        $visits = $new->visits()->get();
        $this->assertCount(1, $visits);
        $this->assertSame(1, $visits->first()->visit_number);
        $this->assertSame('2026-09-09', $visits->first()->visit_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(118.0, (float) $visits->first()->muac, 0.001);

        // One child identity, two episodes; nothing written to Children.
        $this->assertSame(1, FollowUpChild::query()->distinct()->count('id_number'));
        $this->assertSame(0, Child::count());
    }

    // =================================================================
    // TEST 5 - Old episode unchanged after readmission
    // =================================================================

    public function test_old_follow_up_episode_remains_unchanged_after_readmission(): void
    {
        $previous = $this->episode('referred_medical_inpt');
        $before = $this->snapshot($previous);

        $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData());

        $this->assertInstanceOf(FollowUpChild::class, $new);
        $this->assertSame($before, $this->snapshot($previous));

        $previous->refresh();

        $this->assertTrue($previous->isLocked());
        $this->assertSame('referred_medical_inpt', $previous->discharge_outcome);
        $this->assertSame('2026-08-19', $previous->admission_date->format('Y-m-d'));
        $this->assertSame('2026-08-26', $previous->discharge_date->format('Y-m-d'));
        $this->assertSame(2, $previous->visits()->count());
        $this->assertSame(3, FollowUpChildVisit::count());
    }

    // =================================================================
    // TESTS 6-8 - Eligible closed outcomes offer Readmission
    // =================================================================

    public function test_closed_with_discharge_to_otp_offers_readmission(): void
    {
        $this->assertReadmissionOffered($this->episode('discharge_to_opt'));
    }

    public function test_closed_with_discharge_to_other_offers_readmission(): void
    {
        $this->assertReadmissionOffered($this->episode('discharge_to_other'));
    }

    public function test_closed_with_referred_for_medical_reason_offers_readmission(): void
    {
        $this->assertReadmissionOffered($this->episode('referred_medical_inpt'));
    }

    // =================================================================
    // TESTS 9-11 - Ineligible closed outcomes offer no Readmission
    // =================================================================

    public function test_closed_with_cured_offers_no_readmission(): void
    {
        $record = $this->episode('cured');

        $this->assertTrue($record->isLocked());
        $this->assertReadmissionNotOffered($record);
    }

    public function test_closed_with_non_responded_offers_no_readmission(): void
    {
        $record = $this->openEpisode();

        // Non Responded is set by a person and closes the case.
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
        $this->assertFalse(ChildFollowUpTransfer::hasOpenEpisode(self::CHILD_ID));
        $this->assertSame(1, ReferralCandidates::closedFollowUps()->count());

        // History stays, nothing is deleted, no new episode is opened.
        $this->assertSame(2, $record->visits()->count());
        $this->assertSame(1, FollowUpChild::count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record]);

        $this->assertReadmissionNotOffered($record);
    }

    public function test_closed_with_died_offers_no_readmission(): void
    {
        $record = $this->episode('died');

        $this->assertTrue($record->isLocked());
        $this->assertReadmissionNotOffered($record);
    }

    // =================================================================
    // TEST 12 - Referral Centre recognises a closed episode by ID number
    // =================================================================

    public function test_a_child_with_a_closed_follow_up_is_recognised_by_id_number_in_the_referral_centre(): void
    {
        $previous = $this->episode('referred_medical_inpt');
        $before = $this->snapshot($previous);

        // The same ID number, screened SAM again, under any spelling of the name.
        $child = Child::factory()->create([
            'child_id' => self::CHILD_ID,
            'name' => 'محمد عبدالكريم عليوة',
            'muac_mm' => 110,
            'date_of_reporting' => '2026-09-09',
        ]);

        $this->assertTrue(ChildDuplicateChecker::isKnownChild(self::CHILD_ID));

        // Known from the closed history: not pending, not new.
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($child));
        $this->assertSame(0, ReferralCandidates::query()->count(), 'A closed history keeps the child out of the referable set.');

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->assertCanSeeTableRecords([$child])
            ->assertTableColumnStateSet('referral_status', __('ui.referral_center.status.previously_followed'), $child);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PENDING)
            ->assertCanNotSeeTableRecords([$child]);

        // A bulk referral leaves the child alone: no duplicate episode.
        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, $result['skipped_closed']);
        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
        $this->assertSame($before, $this->snapshot($previous));
    }

    // =================================================================
    // TEST 13 - SAM/MAM with a closed episode is not treated as new
    // =================================================================

    public function test_sam_and_mam_children_with_a_closed_episode_are_not_treated_as_new(): void
    {
        $cases = [
            ['id' => '470828001', 'muac' => 110, 'outcome' => 'cured'],
            ['id' => '470828002', 'muac' => 120, 'outcome' => 'non_responded'],
            ['id' => '470828003', 'muac' => 110, 'outcome' => 'died'],
            ['id' => '470828004', 'muac' => 120, 'outcome' => 'discharge_to_opt'],
        ];

        $children = [];

        foreach ($cases as $case) {
            $this->episode($case['outcome'], ['id_number' => $case['id']]);

            $children[$case['id']] = Child::factory()->create([
                'child_id' => $case['id'],
                'muac_mm' => $case['muac'],
                'date_of_reporting' => '2026-09-09',
            ]);
        }

        // A first-time SAM child, for contrast: the only one that is new.
        $pending = Child::factory()->create(['child_id' => '470828009', 'muac_mm' => 110, 'date_of_reporting' => '2026-09-09']);

        foreach ($children as $id => $child) {
            $this->assertSame(
                ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED,
                ReferralCandidates::statusFor($child),
                "[{$id}] must be recognised from the closed episode, not read as new.",
            );
        }

        $this->assertSame(ReferralCandidates::STATUS_PENDING, ReferralCandidates::statusFor($pending));
        $this->assertSame([$pending->getKey()], ReferralCandidates::query()->pluck('id')->all());

        $summary = ReferralCandidates::statusSummary();
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(4, $summary['previously_followed']);

        // Referring everything: the new child, and the children back after a
        // cure or a non-response, each get a NEW episode that follows the
        // closed one (the closed one is never re-opened). The child back after
        // an other exit is left for the one-child Readmission action, and the
        // child who died is refused.
        $result = ReferralProcessor::refer(array_merge(
            array_map(fn (Child $child): int => $child->getKey(), array_values($children)),
            [$pending->getKey()],
        ));

        $this->assertSame(3, $result['referred']);
        $this->assertSame(1, $result['skipped_closed']);
        $this->assertSame(1, $result['skipped_died']);

        $expected = ['470828001' => 2, '470828002' => 2, '470828003' => 1, '470828004' => 1];

        foreach ($expected as $id => $count) {
            $this->assertSame($count, FollowUpChild::where('id_number', $id)->count(), "[{$id}] has the wrong number of episodes.");
        }

        // The closed episodes themselves are untouched.
        foreach ($cases as $case) {
            $this->assertSame(1, FollowUpChild::where('id_number', $case['id'])->where('discharge_outcome', $case['outcome'])->count());
        }

        $this->assertSame(1, FollowUpChild::where('id_number', '470828009')->count());
    }

    // =================================================================
    // TEST 14 - Visit-level attended/missed history keeps working
    // =================================================================

    public function test_visit_level_attended_and_missed_history_still_works_for_a_defaulter(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'discharge_outcome' => 'defaulted',
                'discharge_date' => '2026-09-09',
                'visits' => [
                    ['visit_date' => '2026-08-19', 'status' => 'attended', 'muac' => 110],
                    ['visit_date' => '2026-08-26', 'status' => 'missed'],
                    ['visit_date' => '2026-09-02', 'status' => 'attended', 'muac' => 112],
                    ['visit_date' => '2026-09-09', 'status' => 'missed'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        $this->assertSame('defaulted', $record->discharge_outcome);
        $this->assertTrue($record->isLocked());

        // Four visits, each as recorded: no visit deleted, none invented.
        $this->assertCount(4, $record->visits);
        $this->assertSame([1, 2, 3, 4], $record->visits->pluck('visit_number')->all());
        $this->assertSame(
            ['attended', 'missed', 'attended', 'missed'],
            $record->visits->pluck('status')->all(),
        );
        $this->assertNull($record->visits[1]->muac);
        $this->assertNull($record->visits[3]->muac);
        $this->assertEqualsWithDelta(112.0, (float) $record->visits[2]->muac, 0.001);

        $this->assertSame([
            __('fields.under_follow_up'),
            __('fields.visit_outcome_missed'),
            __('fields.visit_outcome_returned'),
            __('fields.visit_outcome_missed'),
        ], $record->visits->map(fn (FollowUpChildVisit $visit): string => FollowUpChildResource::visitOutcome($visit))->all());

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableColumnStateSet('missed_visits', 2, $record);

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertSuccessful()
            ->assertSee(__('fields.visit_outcome_missed'))
            ->assertSee(__('fields.visit_outcome_returned'));

        // The closed case refuses a further save: the child comes back
        // through a readmission into a new case, not into this one.
        $before = $this->snapshot($record);

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['notes' => 'came back'])
            ->call('save')
            ->assertNotified(__('fields.record_locked_notice'));

        $record->refresh();

        $this->assertSame($before['record'], $this->snapshot($record)['record']);
        $this->assertSame(1, FollowUpChild::count());
        $this->assertTrue($record->canBeReadmitted());
    }
}
