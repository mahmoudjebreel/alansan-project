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
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * F11: a child whose latest closed episode ended as died is named as such in
 * the Referral Centre - status, filter and counter - before anybody tries to
 * refer them, and is never referable.
 *
 * F7: every referral records how the new episode is classified.
 */
class ReferralCentreDiedStatusTest extends TestCase
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

    public function test_a_child_who_died_reads_as_died_everywhere_the_centre_answers(): void
    {
        $this->episode('470300001', 'died');
        $died = $this->screening('470300001');

        $this->episode('470300002', 'cured');
        $closed = $this->screening('470300002');

        $this->assertSame(ReferralCandidates::STATUS_DIED, ReferralCandidates::statusFor($died));
        $this->assertSame(ReferralCandidates::STATUS_DIED, $this->selectedStatus($died));
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, $this->selectedStatus($closed));

        $this->assertSame(1, ReferralCandidates::statusSummary()['died']);
        $this->assertSame(1, ReferralCandidates::statusSummary()['previously_followed']);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_DIED)
            ->assertCanSeeTableRecords([$died])
            ->assertCanNotSeeTableRecords([$closed])
            ->assertTableColumnStateSet('referral_status', __('ui.referral_center.status.died'), $died)
            ->assertTableActionHidden('readmit', $died);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$died]);
    }

    public function test_a_death_in_the_trash_still_keeps_the_child_out_of_the_referable_list(): void
    {
        $this->episode('470300003', 'died')->delete();
        $child = $this->screening('470300003');

        $this->assertSame(ReferralCandidates::STATUS_DIED, $this->selectedStatus($child));
        $this->assertFalse(ReferralCandidates::query()->whereKey($child->getKey())->exists());
        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['skipped_died']);
        $this->assertSame(0, FollowUpChild::where('id_number', '470300003')->count());
    }

    public function test_a_referral_records_the_classification_of_the_new_episode(): void
    {
        $this->episode('470300004', 'cured');
        $child = $this->screening('470300004');

        ReferralProcessor::refer([$child->getKey()]);

        $entry = Activity::query()->where('log_name', 'referral')->where('event', 'referred')->latest('id')->firstOrFail();

        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $entry->properties['admission_classification']);

        $this->screening('470300005');
        ReferralProcessor::refer([Child::where('child_id', '470300005')->value('id')]);

        $entry = Activity::query()->where('log_name', 'referral')->where('event', 'referred')->latest('id')->firstOrFail();
        $this->assertSame(FollowUpChild::ADMISSION_NEW, $entry->properties['admission_classification']);
    }

    private function selectedStatus(Child $child): ?string
    {
        return ReferralCandidates::overview()
            ->whereKey($child->getKey())
            ->select('children.*')
            ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
            ->first()?->referral_status;
    }

    private function episode(string $idNumber, string $outcome): FollowUpChild
    {
        return FollowUpChild::create([
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
        ]);
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
}
