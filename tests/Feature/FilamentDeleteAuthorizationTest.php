<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\ListPregnantLactatingWomen;
use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDeleteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_viewer_cannot_delete_child(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');

        $child = Child::factory()->create();

        Livewire::actingAs($viewer)
            ->test(ListChildren::class)
            ->assertTableBulkActionHidden(DeleteBulkAction::class);

        $this->assertDatabaseHas('children', ['id' => $child->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_child(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $child = Child::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListChildren::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$child]);

        $this->assertSoftDeleted('children', ['id' => $child->id]);
    }

    public function test_viewer_cannot_delete_pregnant_lactating_woman(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');

        $record = PregnantLactatingWoman::factory()->create();

        Livewire::actingAs($viewer)
            ->test(ListPregnantLactatingWomen::class)
            ->assertTableBulkActionHidden(DeleteBulkAction::class);

        $this->assertDatabaseHas('pregnant_lactating_women', ['id' => $record->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_pregnant_lactating_woman(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $record = PregnantLactatingWoman::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListPregnantLactatingWomen::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$record]);

        $this->assertSoftDeleted('pregnant_lactating_women', ['id' => $record->id]);
    }
}
