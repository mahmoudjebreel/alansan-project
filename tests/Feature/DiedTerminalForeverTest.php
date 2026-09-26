<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use App\Support\TerminalChild;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Any genuine died episode on file makes the child terminal for good - trash
 * included, and whatever later episode exists, is closed, is deleted, or is
 * restored. The historical date rule is unchanged: history dated before or on
 * the death stays valid, anything after it is refused.
 */
class DiedTerminalForeverTest extends TestCase
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

    public function test_1_a_died_episode_with_nothing_after_it_is_terminal(): void
    {
        $this->episode('470020001', 'cured', '2026-03-01', '2026-03-20');
        $this->episode('470020001', 'died', '2026-05-01', '2026-05-20');

        $this->assertTerminalEverywhere('470020001');
    }

    public function test_2_a_later_episode_moved_to_the_trash_does_not_hide_the_death(): void
    {
        // The reported case: cured, died, then a later episode, trashed.
        $this->episode('470020002', 'cured', '2026-03-01', '2026-03-20');
        $this->episode('470020002', 'died', '2026-05-01', '2026-05-20');
        $this->episode('470020002', 'cured', '2026-07-01', '2026-07-20')->delete();

        $this->assertTerminalEverywhere('470020002');
    }

    public function test_2b_a_later_live_closed_episode_does_not_hide_the_death_either(): void
    {
        $this->episode('470020003', 'died', '2026-05-01', '2026-05-20');
        $this->episode('470020003', 'non_responded', '2026-07-01', '2026-07-20');

        $this->assertTerminalEverywhere('470020003');
    }

    public function test_3_a_died_episode_in_the_trash_is_still_terminal(): void
    {
        $this->episode('470020004', 'died', '2026-05-01', '2026-05-20')->delete();

        $this->assertTerminalEverywhere('470020004');
    }

    public function test_4_restoring_a_later_episode_never_makes_the_child_active_again(): void
    {
        $this->episode('470020005', 'died', '2026-05-01', '2026-05-20');
        $later = $this->episode('470020005', 'cured', '2026-07-01', '2026-07-20');
        $later->delete();

        // The restore itself is refused (F9-C)...
        $this->assertFalse(Livewire::test(Trash::class)->instance()->restore('follow_up_child', $later->id));
        $this->assertTrue($later->fresh()->trashed());

        // ...and even if the later episode were back on file by any route,
        // the child stays terminal: no new episode opens.
        FollowUpChild::withoutEvents(fn () => FollowUpChild::onlyTrashed()->whereKey($later->id)->update(['deleted_at' => null]));
        $this->assertFalse($later->fresh()->trashed());

        $this->assertTerminalEverywhere('470020005');
    }

    public function test_5_the_historical_date_rule_is_unchanged(): void
    {
        $this->episode('470020006', 'died', '2026-05-01', '2026-05-20');

        // A screening before or on the death's date is history; after, refused.
        $this->assertNull(TerminalChild::refusesScreening('470020006', '2026-05-10'));
        $this->assertNull(TerminalChild::refusesScreening('470020006', '2026-05-20'));
        $this->assertNotNull(TerminalChild::refusesScreening('470020006', '2026-05-21'));

        // An uploaded episode admitted before or on the died episode's
        // admission is history; after it, refused.
        $this->assertSame([], $this->importRefusals('470020006', '2026-04-01'));
        $this->assertSame([], $this->importRefusals('470020006', '2026-05-01'));
        $this->assertNotSame([], $this->importRefusals('470020006', '2026-05-02'));

        // A trashed episode from before or on the death restores; after, not.
        $before = $this->episode('470020006', 'defaulted', '2026-03-01', '2026-03-20');
        $onTheDay = $this->episode('470020006', 'non_responded', '2026-05-01', '2026-05-01');
        $after = $this->episode('470020006', 'cured', '2026-06-01', '2026-06-20');

        foreach ([$before, $onTheDay, $after] as $episode) {
            $episode->delete();
        }

        $this->assertNull(TerminalChild::refusesRestore($before->fresh()));
        $this->assertNull(TerminalChild::refusesRestore($onTheDay->fresh()));
        $this->assertNotNull(TerminalChild::refusesRestore($after->fresh()));

        // Whatever is restored, the child stays terminal.
        $this->assertTrue($before->fresh()->restore());
        $this->assertTrue(FollowUpChild::isTerminal('470020006'));
    }

    /**
     * Every reader of the terminal state says the same thing, and nothing
     * can open a new episode for the child.
     */
    private function assertTerminalEverywhere(string $idNumber): void
    {
        $this->assertTrue(FollowUpChild::isTerminal($idNumber), 'isTerminal()');
        $this->assertArrayHasKey($idNumber, FollowUpChild::terminalEpisodes(), 'terminalEpisodes()');
        $this->assertNull(FollowUpChild::readmissionClassificationFor($idNumber));
        $this->assertNull(FollowUpChild::readmittableEpisodeFor($idNumber));

        $episodes = FollowUpChild::withTrashed()->where('id_number', $idNumber)->count();

        // New, follow-up, readmission and relapse from a screening...
        $child = $this->screening($idNumber);
        $this->assertNull(ChildFollowUpTransfer::refer($child), 'refer()');
        $this->assertNull(ChildFollowUpTransfer::readmit($child), 'readmit()');

        // ...the Referral Centre...
        $this->assertSame(ReferralCandidates::STATUS_DIED, ReferralCandidates::statusFor($child));
        $this->assertSame(ReferralCandidates::STATUS_DIED, ReferralCandidates::overview()
            ->whereKey($child->getKey())
            ->select('children.*')
            ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
            ->first()->referral_status);
        $this->assertFalse(ReferralCandidates::query()->whereKey($child->getKey())->exists());
        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['skipped_died']);

        // ...and a new screening dated after the death.
        $this->assertNotNull(TerminalChild::refusesScreening($idNumber, '2026-09-01'));

        $this->assertSame($episodes, FollowUpChild::withTrashed()->where('id_number', $idNumber)->count(), 'No episode was opened.');
    }

    /** @return array<string> */
    private function importRefusals(string $idNumber, string $admitted): array
    {
        TerminalChild::forget();

        return array_values(array_filter(
            TerminalChild::forImportedFollowUpRow(['id_number' => $idNumber, 'admission_date' => $admitted]),
            static fn (string $message): bool => $message === TerminalChild::message(),
        ));
    }

    private function episode(string $idNumber, string $outcome, string $admitted, ?string $discharged): FollowUpChild
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
            'admission_date' => $admitted,
            'discharge_date' => $discharged,
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
