<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission that gates the Notification Settings and Notification Log
 * pages. Granted to Super Admin only, per the brief.
 *
 * Super Admin already passes every check through the Gate::before() hook, but
 * the pivot row is added anyway so the Roles screen shows it, matching what
 * SuperAdminPermissionsSeeder does for every other permission.
 */
return new class extends Migration
{
    private const PERMISSION = 'notifications.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => self::PERMISSION]);

        Role::where('name', 'Super Admin')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', self::PERMISSION)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
