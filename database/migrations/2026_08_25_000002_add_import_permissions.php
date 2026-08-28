<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Bulk Excel import permissions, one per module.
     */
    private array $permissions = [
        'children.import',
        'pregnant.import',
        'group_sessions.import',
        'mother_to_mother.import',
        'individual_counseling.import',
        'follow_up_children.import',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Admin and Data Entry may bulk-import. Super Admin is covered by the
        // Gate::before rule in AppServiceProvider, so it needs no grant here.
        foreach (['Admin', 'Data Entry'] as $roleName) {
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
