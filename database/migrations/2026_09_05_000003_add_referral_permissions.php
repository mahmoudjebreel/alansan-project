<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Referral Centre permission.
 *
 * Referring opens a follow-up episode, so it is granted to exactly the roles
 * that may already create one by hand. Super Admin is covered by the
 * Gate::before rule in AppServiceProvider and needs no grant here.
 */
return new class extends Migration
{
    private string $permission = 'children.refer';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => $this->permission]);

        foreach (['Admin', 'Data Entry'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($this->permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', $this->permission)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
