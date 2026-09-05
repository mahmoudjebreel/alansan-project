<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_the_seeder_grants_the_role_every_permission_that_exists(): void
    {
        $all = Permission::pluck('name')->sort()->values()->all();
        $granted = Role::findByName('Super Admin')->permissions->pluck('name')->sort()->values()->all();

        $this->assertNotEmpty($all);
        $this->assertSame($all, $granted);
    }

    public function test_a_super_admin_holds_every_permission(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (Permission::pluck('name') as $permission) {
            $this->assertTrue(
                $superAdmin->can($permission),
                "Super Admin cannot [{$permission}].",
            );

            // The Filament resources ask Spatie directly rather than going
            // through the Gate, so the role must really hold the permission.
            $this->assertTrue(
                $superAdmin->hasPermissionTo($permission),
                "Super Admin does not hold [{$permission}].",
            );
        }

        $this->assertTrue($superAdmin->can('children.delete'));
        $this->assertTrue($superAdmin->can('pregnant.export'));
    }

    public function test_a_super_admin_also_passes_a_permission_that_does_not_exist_yet(): void
    {
        // Guaranteed by the Gate::before hook, not by the seeder.
        $this->assertTrue($this->superAdmin()->can('any.newly.added.permission'));
    }

    public function test_a_super_admin_reaches_the_module_screens(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(ChildResource::canViewAny());
        $this->assertTrue(ChildResource::canCreate());
        $this->assertTrue(PregnantLactatingWomanResource::canCreate());
        $this->assertTrue(IndividualCounselingResource::canCreate());

        Livewire::test(ListChildren::class)->assertSuccessful();
    }

    public function test_the_other_roles_did_not_gain_anything(): void
    {
        // Both roles gained children.refer with the Referral Centre: the two
        // roles that may already open a follow-up episode by hand are the two
        // that may open one from a reviewed upload.
        $expected = [
            'Admin' => 43,
            'Data Entry' => 25,
            'Viewer' => 12,
            'M&E' => 8,
        ];

        foreach ($expected as $role => $count) {
            $this->assertCount(
                $count,
                Role::findByName($role)->permissions,
                "The [{$role}] role no longer holds exactly {$count} permissions.",
            );
        }

        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->assertFalse($viewer->can('children.delete'));
        $this->assertFalse($viewer->can('users.manage'));

        $dataEntry = User::factory()->create();
        $dataEntry->assignRole('Data Entry');
        $this->assertFalse($dataEntry->can('children.delete'));
        $this->assertFalse($dataEntry->can('children.export'));

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->assertFalse($admin->can('users.manage'));
        $this->assertFalse($admin->can('roles.manage'));
        $this->assertFalse($admin->can('settings.manage'));
        $this->assertFalse($admin->can('backup.manage'));
    }
}
