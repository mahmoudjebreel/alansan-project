<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Referral Centre's existing "Refer" action and a child who is back after
 * a closed episode.
 *
 *   no episode on file           -> a new episode, New
 *   latest closed = cured        -> a new episode: Readmission after Relapse
 *                                   after a SAM/MAM cure, New otherwise
 *   latest closed = non-responded-> a new episode, New
 *   latest closed = died         -> refused
 *   latest closed = defaulted    -> left alone: the one-child Readmission
 *   or an other exit                action is the way back, as before
 *
 * The Referral Centre decides none of the classifications: the transfer and
 * the model do. The closed episode is never touched.
 */
class ReferralCentreReturnAfterClosedEpisodeTest extends TestCase
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

    public function test_a_child_with_no_episode_is_referred_as_new(): void
    {
        $child = $this->screening('470700001');

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);

        $episode = FollowUpChild::where('id_number', '470700001')->sole();
        $this->assertNull($episode->readmissionClassification());
        $this->assertSame(FollowUpChild::ADMISSION_NEW, $episode->derivedAdmissionType());
    }

    public function test_a_child_back_after_a_cured_sam_episode_is_referred_as_a_readmission_after_relapse(): void
    {
        $cured = $this->closed('470700002', 'cured');
        $before = $cured->getAttributes();

        $child = $this->screening('470700002');

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);

        $episode = $this->newestEpisode('470700002');
        $this->assertSame($cured->getKey(), $episode->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $episode->readmissionClassification());
        $this->assertSame($before, $cured->fresh()->getAttributes(), 'The cured episode is untouched.');
    }

    public function test_a_child_back_after_a_second_cure_is_again_a_readmission_after_relapse(): void
    {
        $first = $this->closed('470700003', 'cured', ['admission_date' => '2026-03-01', 'discharge_date' => '2026-04-01']);
        $this->closed('470700003', 'cured', [
            'admission_date' => '2026-05-01', 'discharge_date' => '2026-06-01',
            'previous_follow_up_child_id' => $first->getKey(),
        ]);

        $child = $this->screening('470700003');

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);

        $episode = $this->newestEpisode('470700003');
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $episode->readmissionClassification());
        $this->assertSame(FollowUpChild::ADMISSION_READMISSION, $episode->derivedAdmissionType());
    }

    public function test_a_child_back_after_a_cure_with_no_sam_mam_admission_is_referred_as_new(): void
    {
        $this->closed('470700004', 'cured', ['admitted_with' => null]);

        $child = $this->screening('470700004');

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);
        $this->assertNull($this->newestEpisode('470700004')->readmissionClassification());
    }

    public function test_a_child_back_after_non_responded_is_referred_as_new(): void
    {
        $this->closed('470700005', 'non_responded');

        $child = $this->screening('470700005');

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);

        $episode = $this->newestEpisode('470700005');
        $this->assertNull($episode->previous_follow_up_child_id);
        $this->assertNull($episode->readmissionClassification());
    }

    public function test_a_child_who_died_is_refused(): void
    {
        $this->closed('470700006', 'died');

        $result = ReferralProcessor::refer([$this->screening('470700006')->getKey()]);

        $this->assertSame(1, $result['skipped_died']);
        $this->assertSame(1, FollowUpChild::where('id_number', '470700006')->count());
    }

    public function test_defaulted_and_other_exits_keep_the_one_child_readmission_path(): void
    {
        foreach (FollowUpChild::READMISSION_OUTCOMES as $index => $outcome) {
            $idNumber = '47070001' . $index;
            $this->closed($idNumber, $outcome);
            $child = $this->screening($idNumber);

            // The bulk referral leaves them alone, exactly as before.
            $result = ReferralProcessor::refer([$child->getKey()]);
            $this->assertSame(1, $result['skipped_closed'], "[{$outcome}]");
            $this->assertSame(1, FollowUpChild::where('id_number', $idNumber)->count(), "[{$outcome}]");

            // The existing Readmission action opens it, as a readmission.
            Livewire::test(ReferralCenter::class)
                ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
                ->assertTableActionVisible('readmit', $child)
                ->callTableAction('readmit', $child);

            $episode = $this->newestEpisode($idNumber);
            $this->assertSame(
                $outcome === FollowUpChild::DEFAULTED_OUTCOME
                    ? FollowUpChild::READMISSION_AFTER_DEFAULTED
                    : FollowUpChild::READMISSION_AFTER_OTHER,
                $episode->readmissionClassification(),
                "[{$outcome}]",
            );
        }
    }

    public function test_the_readmission_action_is_not_offered_after_a_cure_or_a_non_response(): void
    {
        foreach (['cured' => '470700021', 'non_responded' => '470700022'] as $outcome => $idNumber) {
            $this->closed($idNumber, $outcome);
            $child = $this->screening($idNumber);

            Livewire::test(ReferralCenter::class)
                ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
                ->assertTableActionHidden('readmit', $child);
        }
    }

    public function test_the_existing_refer_button_opens_the_episode_and_a_second_click_opens_nothing(): void
    {
        $this->closed('470700030', 'cured');
        $child = $this->screening('470700030');

        $page = Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->callTableBulkAction('refer', [$child]);

        $page->assertHasNoTableBulkActionErrors();
        $this->assertSame(2, FollowUpChild::where('id_number', '470700030')->count());
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $this->newestEpisode('470700030')->readmissionClassification());

        // Confirmed again: the child is now under follow-up, nothing opens.
        $again = ReferralProcessor::refer([$child->getKey()]);
        $this->assertSame(1, $again['skipped_active']);
        $this->assertSame(2, FollowUpChild::where('id_number', '470700030')->count());
    }

    private function closed(string $idNumber, string $outcome, array $attributes = []): FollowUpChild
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
            'admission_date' => '2026-07-01',
            'discharge_date' => '2026-08-01',
            'discharge_outcome' => $outcome,
        ], $attributes))->fresh();
    }

    private function screening(string $idNumber): Child
    {
        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $idNumber,
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => '2026-09-01',
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => 110,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
        ]);
    }

    private function newestEpisode(string $idNumber): FollowUpChild
    {
        return FollowUpChild::where('id_number', $idNumber)->orderByDesc('id')->firstOrFail();
    }
}
