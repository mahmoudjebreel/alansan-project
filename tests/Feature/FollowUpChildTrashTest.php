<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Models\FollowUpChild;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a Follow Up Child must be reversible, exactly like every other
 * soft-deletable module.
 *
 * Two separate defects made it permanent: the model destroyed the visit rows
 * on an ordinary (soft) delete even though visits are not soft-deletable, and
 * the module was missing from the Trash registry altogether, so the record
 * could not be found again to restore in the first place.
 */
class FollowUpChildTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function childWithVisits(): FollowUpChild
    {
        $child = FollowUpChild::factory()->create([
            'child_name' => 'طفل المتابعة',
            'id_number' => '123456789',
        ]);

        $child->visits()->create(['visit_number' => 1, 'visit_date' => '2026-01-05', 'muac' => 112.0]);
        $child->visits()->create(['visit_number' => 2, 'visit_date' => '2026-02-05', 'muac' => 118.0]);

        return $child;
    }

    public function test_soft_deleting_keeps_the_recorded_visits(): void
    {
        $child = $this->childWithVisits();

        $child->delete();

        $this->assertSame(2, $child->visits()->count(), 'A soft delete must not destroy the MUAC readings.');
    }

    public function test_a_deleted_record_is_listed_in_the_trash(): void
    {
        $this->actingAsRole('Super Admin');

        $child = $this->childWithVisits();
        $child->delete();

        $rows = Livewire::test(Trash::class)->instance()->getRows();

        $this->assertSame(1, $rows->total());
        $row = $rows->first();
        $this->assertSame('follow_up_child', $row['type']);
        $this->assertSame('طفل المتابعة', $row['name']);
        $this->assertSame('123456789', $row['identifier']);
    }

    public function test_restoring_brings_back_the_record_with_its_visits(): void
    {
        $this->actingAsRole('Super Admin');

        $child = $this->childWithVisits();
        $child->delete();

        $restored = Livewire::test(Trash::class)->instance()->restore('follow_up_child', $child->id);

        $this->assertTrue($restored);

        $reloaded = FollowUpChild::with('visits')->find($child->id);

        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->visits);
        $this->assertEqualsWithDelta(118.0, (float) $reloaded->visits->last()->muac, 0.001);
    }

    public function test_force_deleting_still_clears_the_visits(): void
    {
        $child = $this->childWithVisits();
        $id = $child->id;

        $child->forceDelete();

        $this->assertNull(FollowUpChild::withTrashed()->find($id));
        $this->assertDatabaseMissing('follow_up_child_visits', ['follow_up_child_id' => $id]);
    }
}
