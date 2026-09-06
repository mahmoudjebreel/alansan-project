<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tops an existing database up with any permission that has been added since it
 * was last seeded, and grants the new ones to the roles that should hold them.
 *
 * Unlike RolesAndPermissionsSeeder this seeder is purely additive: it never
 * revokes anything, so permissions that were tuned by hand on the Roles screen
 * survive. That makes it the one to run after deploying to a live server:
 *
 *     php artisan db:seed --class=SyncPermissionsSeeder --force
 *
 * It is idempotent -- running it twice reports "nothing to add" the second time.
 */
class SyncPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        $existing = Permission::where('guard_name', $guard)->pluck('name')->all();
        $missing = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, $existing));

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        $this->report('permissions created', $missing);

        foreach (RolesAndPermissionsSeeder::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            // Only hand over what the role is missing, so manual grants and
            // manual revocations both stay untouched apart from the new names.
            $granted = array_values(array_intersect(
                $missing,
                array_diff($permissions, $role->permissions->pluck('name')->all())
            ));

            foreach ($granted as $name) {
                $role->givePermissionTo($name);
            }

            $this->report("granted to {$roleName}", $granted);
        }

        // Keep the Roles screen showing the full list for Super Admin, which
        // bypasses every check through the Gate::before hook anyway.
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => $guard])
            ->syncPermissions(Permission::where('guard_name', $guard)->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function report(string $label, array $names): void
    {
        $this->command?->info(
            $names === []
                ? "{$label}: nothing to add"
                : "{$label}: " . implode(', ', $names)
        );
    }
}
