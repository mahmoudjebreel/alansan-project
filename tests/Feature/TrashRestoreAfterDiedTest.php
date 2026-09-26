<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\BulkRecordWriter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F9-C: an episode in the trash that is dated after the child's death is not
 * restorable - by the single restore, the bulk restore, or the model itself.
 * History dated before or on the death restores as it always did, the death
 * included.
 */
class TrashRestoreAfterDiedTest extends TestCase
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

    public function test_an_episode_dated_after_the_death_cannot_be_restored(): void
    {
        $this->episode('470100001', 'died', '2026-05-01', '2026-05-20');
        $after = $this->episode('470100001', 'cured', '2026-07-01', '2026-07-20');
        $after->delete();

        $this->assertFalse(Livewire::test(Trash::class)->instance()->restore('follow_up_child', $after->id));
        $this->assertTrue($after->fresh()->trashed());

        // Not by the model either.
        $this->assertFalse($after->fresh()->restore());
        $this->assertTrue($after->fresh()->trashed());
    }

    public function test_history_before_or_on_the_death_restores(): void
    {
        $this->episode('470100002', 'died', '2026-05-01', '2026-05-20');
        $before = $this->episode('470100002', 'defaulted', '2026-03-01', '2026-03-20');
        $onTheDay = $this->episode('470100002', 'non_responded', '2026-05-01', '2026-05-01');
        $before->delete();
        $onTheDay->delete();

        $trash = Livewire::test(Trash::class)->instance();

        $this->assertTrue($trash->restore('follow_up_child', $before->id));
        $this->assertTrue($trash->restore('follow_up_child', $onTheDay->id));
        $this->assertFalse($before->fresh()->trashed());
        $this->assertFalse($onTheDay->fresh()->trashed());
    }

    public function test_the_death_itself_restores(): void
    {
        $died = $this->episode('470100003', 'died', '2026-05-01', '2026-05-20');
        $died->delete();

        $this->assertTrue(Livewire::test(Trash::class)->instance()->restore('follow_up_child', $died->id));
        $this->assertFalse($died->fresh()->trashed());
    }

    public function test_the_bulk_restore_leaves_the_refused_episodes_in_the_trash_and_says_so(): void
    {
        $this->episode('470100004', 'died', '2026-05-01', '2026-05-20');
        $after = $this->episode('470100004', 'cured', '2026-07-01', '2026-07-20');
        $before = $this->episode('470100004', 'defaulted', '2026-03-01', '2026-03-20');
        $unrelated = $this->episode('470100005', 'cured', '2026-07-01', '2026-07-20');

        foreach ([$after, $before, $unrelated] as $episode) {
            $episode->delete();
        }

        Livewire::test(Trash::class)
            ->set('selected', ["follow_up_child:{$after->id}", "follow_up_child:{$before->id}", "follow_up_child:{$unrelated->id}"])
            ->call('restoreSelected')
            ->assertNotified(__('ui.died_terminal.restore_refused_title'));

        $this->assertTrue($after->fresh()->trashed());
        $this->assertFalse($before->fresh()->trashed());
        $this->assertFalse($unrelated->fresh()->trashed());
    }

    public function test_the_set_based_restore_applies_the_guard_itself(): void
    {
        $this->episode('470100006', 'died', '2026-05-01', '2026-05-20');
        $after = $this->episode('470100006', 'cured', '2026-07-01', '2026-07-20');
        $after->delete();

        BulkRecordWriter::restore(FollowUpChild::onlyTrashed()->whereKey($after->id));

        $this->assertTrue($after->fresh()->trashed());
    }

    private function episode(string $idNumber, string $outcome, string $admitted, string $discharged): FollowUpChild
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
}
