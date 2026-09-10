<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\PregnantLactatingWoman;
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

        // Destructive actions use the centralized SweetAlert2 helper, not native
        // confirm(). The expression is an HTML attribute, so its quotes arrive
        // escaped - decode before matching, the way the browser does.
        $decoded = html_entity_decode($html, ENT_QUOTES);
        $this->assertStringContainsString('confirmAction($wire, "restore"', $decoded);
        $this->assertStringContainsString('confirmAction($wire, "forceDelete"', $decoded);
        $this->assertStringNotContainsString('wire:confirm', $html);

        // Blade does not compile directives inside a component's attributes:
        // an `@js(...)` written there is shipped verbatim and every handler on
        // the page becomes a syntax error, which is how the buttons went dead.
        $this->assertStringNotContainsString('@js(', $html);

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
        $this->assertStringNotContainsString('@js(', $html);
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

    public function test_the_header_checkbox_selects_every_row_on_the_page(): void
    {
        $this->actingAsRole('Super Admin');

        $children = Child::factory()->count(3)->create();
        $children->each->delete();
        $woman = PregnantLactatingWoman::factory()->create();
        $woman->delete();

        $component = Livewire::test(Trash::class)->call('selectPage');

        $selected = $component->get('selected');

        $this->assertCount(4, $selected);
        $this->assertContains('pregnant_lactating_woman:' . $woman->id, $selected);
        foreach ($children as $child) {
            $this->assertContains('child:' . $child->id, $selected);
        }
        $this->assertTrue($component->instance()->isPageSelected());

        $component->call('deselectAll');

        $this->assertSame([], $component->get('selected'));
    }

    public function test_restore_selected_restores_only_the_ticked_records(): void
    {
        $this->actingAsRole('Super Admin');

        [$kept, $restored] = Child::factory()->count(2)->create();
        $kept->delete();
        $restored->delete();
        $woman = PregnantLactatingWoman::factory()->create();
        $woman->delete();

        Livewire::test(Trash::class)
            ->set('selected', ['child:' . $restored->id, 'pregnant_lactating_woman:' . $woman->id])
            ->call('restoreSelected')
            ->assertReturned(true)
            ->assertSet('selected', []);

        $this->assertTrue(Child::whereKey($restored->id)->exists());
        $this->assertTrue(PregnantLactatingWoman::whereKey($woman->id)->exists());
        $this->assertTrue(Child::onlyTrashed()->whereKey($kept->id)->exists());
    }

    public function test_select_all_reaches_every_page_of_the_trash(): void
    {
        $this->actingAsRole('Super Admin');

        // More than one page, so the page's keys alone would not cover it.
        Child::factory()->count(30)->create()->each->delete();
        $followUp = FollowUpChild::factory()->create();
        $followUp->delete();

        $component = Livewire::test(Trash::class)->call('selectAll');

        $this->assertTrue($component->get('selectingAll'));
        $this->assertSame(31, $component->instance()->selectedCount());

        $component->call('forceDeleteSelected')->assertReturned(true);

        $this->assertSame(0, Child::withTrashed()->count());
        $this->assertSame(0, FollowUpChild::withTrashed()->count());
        $this->assertFalse($component->get('selectingAll'));
    }

    public function test_unticking_a_row_ends_a_select_all(): void
    {
        $this->actingAsRole('Super Admin');

        $children = Child::factory()->count(3)->create();
        $children->each->delete();

        $component = Livewire::test(Trash::class)->call('selectAll');
        $this->assertTrue($component->get('selectingAll'));

        $remaining = array_slice($component->get('selected'), 1);
        $component->set('selected', $remaining);

        $this->assertFalse($component->get('selectingAll'));
        $this->assertSame(2, $component->instance()->selectedCount());
    }

    public function test_bulk_actions_ignore_malformed_keys_and_need_a_selection(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create();
        $child->delete();

        Livewire::test(Trash::class)
            ->set('selected', ['nonsense', 'unknown:1', 'child:abc'])
            ->call('restoreSelected')
            ->assertReturned(false);

        $this->assertTrue(Child::onlyTrashed()->whereKey($child->id)->exists());
    }

    public function test_bulk_restore_is_blocked_for_users_without_permission(): void
    {
        $this->actingAsRole('Super Admin');
        $child = Child::factory()->create();
        $child->delete();

        $user = User::factory()->create();
        $user->givePermissionTo('trash.view');
        $this->actingAs($user);

        Livewire::test(Trash::class)
            ->set('selected', ['child:' . $child->id])
            ->call('restoreSelected')
            ->assertForbidden();

        Livewire::test(Trash::class)
            ->set('selected', ['child:' . $child->id])
            ->call('forceDeleteSelected')
            ->assertForbidden();

        $this->assertTrue(Child::onlyTrashed()->whereKey($child->id)->exists());
    }

    public function test_the_page_renders_the_selection_controls(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create();
        $child->delete();

        $html = $this->get('/admin/trash')->assertOk()->getContent();
        $decoded = html_entity_decode($html, ENT_QUOTES);

        $this->assertStringContainsString('wire:model.live="selected"', $html);
        $this->assertStringContainsString('value="child:' . $child->id . '"', $html);
        $this->assertStringContainsString("'selectPage' : 'deselectAll'", $decoded);
        $this->assertStringNotContainsString('@js(', $html);
    }

    public function test_the_bulk_bar_appears_once_something_is_selected(): void
    {
        $this->actingAsRole('Super Admin');

        $child = Child::factory()->create();
        $child->delete();

        Livewire::test(Trash::class)
            ->assertDontSee(__('ui.trash.restore_selected'))
            ->set('selected', ['child:' . $child->id])
            ->assertSee(__('ui.trash.restore_selected'))
            ->assertSee(__('ui.trash.force_delete_selected'))
            ->assertSee(__('ui.trash.selected_count', ['count' => 1]));
    }
}
