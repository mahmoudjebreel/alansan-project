<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Models\Child;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrashPageTest extends TestCase
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

    public function test_only_authorized_roles_can_access_the_trash_page(): void
    {
        $this->actingAsRole('Super Admin');
        $this->assertTrue(Trash::canAccess());

        $this->actingAsRole('Admin');
        $this->assertTrue(Trash::canAccess());

        $this->actingAsRole('Data Entry');
        $this->assertFalse(Trash::canAccess());

        $this->actingAsRole('Viewer');
        $this->assertFalse(Trash::canAccess());
    }

    public function test_trashed_records_appear_in_the_unified_list(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create(['name' => 'Deleted Child', 'child_id' => 'CH-TRASH-1']);
        $child->delete();

        $rows = Livewire::test(Trash::class)->instance()->getRows();

        $this->assertSame(1, $rows->total());
        $row = $rows->first();
        $this->assertSame('child', $row['type']);
        $this->assertSame('Deleted Child', $row['name']);
        $this->assertSame('CH-TRASH-1', $row['identifier']);
        $this->assertNotNull($row['deleted_at']);
    }

    public function test_restore_returns_record_to_its_normal_list_with_data_intact(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create(['name' => 'Restore Me']);
        $child->delete();

        $this->assertFalse(Child::whereKey($child->id)->exists());

        Livewire::test(Trash::class)
            ->call('restore', 'child', $child->id)
            ->assertReturned(true);

        $this->assertTrue(Child::whereKey($child->id)->exists());
        $this->assertSame('Restore Me', Child::find($child->id)->name);
    }

    public function test_force_delete_permanently_removes_the_record(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create();
        $child->delete();

        Livewire::test(Trash::class)->call('forceDelete', 'child', $child->id);

        $this->assertFalse(Child::withTrashed()->whereKey($child->id)->exists());
    }

    public function test_trash_page_renders_with_sized_icons_and_sweetalert_confirmations(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create(['name' => 'Render Me', 'child_id' => 'CH-RENDER-1']);
        $child->delete();

        $html = $this->get('/admin/trash')->assertOk()->getContent();

        // Icons render as real, class-sized SVGs (not oversized raw components).
        $this->assertStringContainsString('<svg', $html);

        // Destructive actions use the centralized SweetAlert2 helper, not native confirm().
        $this->assertStringContainsString("confirmAction(\$wire, 'restore'", $html);
        $this->assertStringContainsString("confirmAction(\$wire, 'forceDelete'", $html);
        $this->assertStringNotContainsString('wire:confirm', $html);

        // The table content is present and readable.
        $this->assertStringContainsString('Render Me', $html);
        $this->assertStringContainsString('CH-RENDER-1', $html);
    }

    public function test_backups_page_renders_without_native_confirm(): void
    {
        $this->actingAsRole('Super Admin');

        $html = $this->get('/admin/backups')->assertOk()->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('wire:confirm', $html);
    }

    public function test_restore_is_blocked_for_users_without_permission(): void
    {
        // Grant view (so the page loads) but revoke restore for this user.
        $this->actingAsRole('Super Admin');
        $child = Child::factory()->create();
        $child->delete();

        $user = User::factory()->create();
        $user->givePermissionTo('trash.view');
        $this->actingAs($user);

        Livewire::test(Trash::class)
            ->call('restore', 'child', $child->id)
            ->assertForbidden();

        $this->assertTrue(Child::onlyTrashed()->whereKey($child->id)->exists());
    }
}
