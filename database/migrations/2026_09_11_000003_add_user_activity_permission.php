<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permission for the User Activity monitoring page.
     *
     * Granted to Super Admin only. Super Admin already passes every check
     * through the Gate::before rule; the explicit grant is so the Roles
     * screen lists it. No other role receives it here.
     */
    private const PERMISSION = 'user_activity.view';

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
