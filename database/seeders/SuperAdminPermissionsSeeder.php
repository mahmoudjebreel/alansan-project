<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the "Super Admin" role every permission that currently exists in the
 * permissions table.
 *
 * At runtime Super Admin already bypasses every check through the
 * `Gate::before()` hook in AuthServiceProvider (which is scoped to this role
 * only). This seeder keeps the role <-> permission pivot in sync as well, so
 * that the Roles screen shows the full list instead of an empty one.
 *
 * Must run AFTER RolesAndPermissionsSeeder, which creates the permissions.
 */
class SuperAdminPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'Super Admin']);

        $role->syncPermissions(Permission::all());

        // Attach the role to the seeded super admin account, when it exists.
        User::where('email', 'admin@nutrition-screening.org')
            ->first()
            ?->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
