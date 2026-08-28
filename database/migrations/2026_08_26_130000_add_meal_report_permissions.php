<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions for the MEAL monthly monitoring report.
 *
 * Granted to Admin and to M&E, the two roles that produce reporting output.
 * Super Admin is covered by the Gate::before rule, as with every other
 * permission in this system.
 */
return new class extends Migration
{
    /** @var array<string> */
    private array $permissions = [
        'meal_report.view',
        'meal_report.export',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach (['Admin', 'M&E'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($this->permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', $this->permissions)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
