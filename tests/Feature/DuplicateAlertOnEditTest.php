<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\CreatePregnantLactatingWoman;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\EditPregnantLactatingWoman;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "already registered" alert belongs to registration, not to editing.
 *
 * Creating a Children or Pregnant / Lactating Women record for an ID that is
 * already on file raises the duplicate alert exactly as before. Editing an
 * existing record for that same ID does not: the record on the form is the
 * person's own row, and offering to prefill it from "the previous visit" is
 * only ever a wrong answer there. Everything else the ID check does on the
 * edit form - announcing the follow-up history behind the SAM/MAM referral
 * prompt, re-deriving the mother's visit type - carries on unchanged.
 */
class DuplicateAlertOnEditTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '123456789';

    private const MOTHER_ID = '987654321';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Children
    // -----------------------------------------------------------------

    public function test_creating_a_child_that_already_exists_still_raises_the_duplicate_alert(): void
    {
        Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 130]);

        Livewire::test(CreateChild::class)
            ->set('data.child_id', self::CHILD_ID)
            ->assertDispatched('show-duplicate-visit-alert')
            ->assertDispatched('follow-up-history-known');
    }

    public function test_creating_a_child_known_only_from_a_follow_up_episode_still_raises_the_duplicate_alert(): void
    {
        $this->closedEpisode(self::CHILD_ID, 'defaulted');

        Livewire::test(CreateChild::class)
            ->set('data.child_id', self::CHILD_ID)
            ->assertDispatched('show-duplicate-visit-alert')
            ->assertDispatched('follow-up-history-known');
    }

    public function test_editing_an_existing_child_does_not_raise_the_duplicate_alert(): void
    {
        Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 130, 'date_of_reporting' => '2026-01-01']);
        $record = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 130, 'date_of_reporting' => '2026-02-01']);

        Livewire::test(EditChild::class, ['record' => $record->getKey()])
            // Re-entering the same ID, as a correction would: another
            // active visit for it exists, and the alert still stays away.
            ->set('data.child_id', '')
            ->set('data.child_id', self::CHILD_ID)
            ->assertNotDispatched('show-duplicate-visit-alert')
            ->assertDispatched('follow-up-history-known');
    }

    public function test_editing_a_child_with_a_closed_follow_up_episode_keeps_the_history_announcement_without_the_alert(): void
    {
        $record = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 130]);
        $this->closedEpisode(self::CHILD_ID, 'defaulted');

        Livewire::test(EditChild::class, ['record' => $record->getKey()])
            ->set('data.child_id', '')
            ->set('data.child_id', self::CHILD_ID)
            ->assertNotDispatched('show-duplicate-visit-alert')
            // The SAM/MAM referral prompt on the edit form still learns that
            // a new episode would be a readmission after a default.
            ->assertDispatched('follow-up-history-known', function (string $event, array $params): bool {
                $history = $params[0] ?? $params;

                return $history['child_id'] === self::CHILD_ID
                    && $history['state'] === 'closed'
                    && $history['readmission'] === true
                    && $history['classification'] === __('fields.readmission_after_defaulted');
            });
    }

    public function test_editing_a_child_with_an_open_follow_up_episode_announces_it_without_the_alert(): void
    {
        $record = Child::factory()->create(['child_id' => self::CHILD_ID, 'muac_mm' => 110]);
        FollowUpChild::factory()->create([
            'id_number' => self::CHILD_ID,
            'admitted_with' => 'SAM',
            'admission_date' => '2026-06-01',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        Livewire::test(EditChild::class, ['record' => $record->getKey()])
            ->set('data.child_id', '')
            ->set('data.child_id', self::CHILD_ID)
            ->assertNotDispatched('show-duplicate-visit-alert')
            ->assertDispatched('follow-up-history-known', function (string $event, array $params): bool {
                $history = $params[0] ?? $params;

                return $history['state'] === 'open' && $history['readmission'] === false;
            });
    }

    // -----------------------------------------------------------------
    // Pregnant / Lactating Women
    // -----------------------------------------------------------------

    public function test_creating_a_mother_that_already_exists_still_raises_the_duplicate_alert(): void
    {
        $this->mother('pregnant', '2026-01-01');

        Livewire::test(CreatePregnantLactatingWoman::class)
            ->set('data.mother_id', self::MOTHER_ID)
            ->assertDispatched('show-duplicate-visit-alert')
            ->assertSet('data.visit_type', 'follow_up');
    }

    public function test_editing_an_existing_mother_does_not_raise_the_duplicate_alert(): void
    {
        $this->mother('pregnant', '2026-01-01');
        $record = $this->mother('pregnant', '2026-02-01');

        Livewire::test(EditPregnantLactatingWoman::class, ['record' => $record->getKey()])
            ->set('data.mother_id', '')
            ->set('data.mother_id', self::MOTHER_ID)
            ->assertNotDispatched('show-duplicate-visit-alert')
            // The visit type is still re-derived against her other visit.
            ->assertSet('data.visit_type', 'follow_up')
            ->set('data.status_type', 'lactating')
            ->assertSet('data.visit_type', 'new');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function closedEpisode(string $childId, string $outcome): FollowUpChild
    {
        return FollowUpChild::factory()->create([
            'id_number' => $childId,
            'admitted_with' => 'SAM',
            'admission_date' => '2026-05-01',
            'discharge_date' => '2026-05-20',
            'discharge_outcome' => $outcome,
        ]);
    }

    private function mother(string $statusType, string $dateOfReporting): PregnantLactatingWoman
    {
        return PregnantLactatingWoman::factory()->create([
            'mother_id' => self::MOTHER_ID,
            'status_type' => $statusType,
            'date_of_reporting' => $dateOfReporting,
        ]);
    }
}
