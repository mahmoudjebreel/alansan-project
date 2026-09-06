<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Canonical definition of every permission and of the default grant for each
 * role. This is the single source of truth: SyncPermissionsSeeder reads the
 * same constants when topping an existing database up.
 *
 * run() uses syncPermissions(), so it resets a role back to the defaults below.
 * That is correct for a fresh install but destructive on a live database where
 * roles may have been tuned from the Roles screen -- use SyncPermissionsSeeder
 * there instead.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** Every permission the application checks for. */
    public const PERMISSIONS = [
        // Children module
        'children.view',
        'children.create',
        'children.edit',
        'children.delete',
        'children.export',
        // Pregnant/Lactating Women module
        'pregnant.view',
        'pregnant.create',
        'pregnant.edit',
        'pregnant.delete',
        'pregnant.export',
        // Group Sessions module
        'group_sessions.view',
        'group_sessions.create',
        'group_sessions.edit',
        'group_sessions.delete',
        'group_sessions.export',
        // Mother to Mother module
        'mother_to_mother.view',
        'mother_to_mother.create',
        'mother_to_mother.edit',
        'mother_to_mother.delete',
        'mother_to_mother.export',
        // Individual Counseling module
        'individual_counseling.view',
        'individual_counseling.create',
        'individual_counseling.edit',
        'individual_counseling.delete',
        'individual_counseling.export',
        // Follow Up Children module
        'follow_up_children.view',
        'follow_up_children.create',
        'follow_up_children.edit',
        'follow_up_children.delete',
        'follow_up_children.export',
        // System
        'users.manage',
        'roles.manage',
        'settings.manage',
        'backup.manage',
        'cache.manage',
        'activity.view',
        // Super Admin data-action notifications
        'notifications.manage',
        // Bulk Excel import
        'children.import',
        'pregnant.import',
        'group_sessions.import',
        'mother_to_mother.import',
        'individual_counseling.import',
        'follow_up_children.import',
        // Referral Centre: reviewing an upload's SAM/MAM children
        'children.refer',
        // Trash (Recycle Bin)
        'trash.view',
        'trash.restore',
        'trash.force_delete',
        // MEAL monthly monitoring report
        'meal_report.view',
        'meal_report.export',
    ];

    /**
     * Default permissions per role. "Super Admin" is absent on purpose: it
     * bypasses every check through the Gate::before hook, and
     * SuperAdminPermissionsSeeder syncs the pivot with everything that exists.
     */
    public const ROLE_PERMISSIONS = [
        'Admin' => [
            'children.view', 'children.create', 'children.edit', 'children.delete', 'children.export',
            'pregnant.view', 'pregnant.create', 'pregnant.edit', 'pregnant.delete', 'pregnant.export',
            'group_sessions.view', 'group_sessions.create', 'group_sessions.edit', 'group_sessions.delete', 'group_sessions.export',
            'mother_to_mother.view', 'mother_to_mother.create', 'mother_to_mother.edit', 'mother_to_mother.delete', 'mother_to_mother.export',
            'individual_counseling.view', 'individual_counseling.create', 'individual_counseling.edit', 'individual_counseling.delete', 'individual_counseling.export',
            'follow_up_children.view', 'follow_up_children.create', 'follow_up_children.edit', 'follow_up_children.delete', 'follow_up_children.export',
            'activity.view',
            'trash.view', 'trash.restore', 'trash.force_delete',
            'children.import', 'pregnant.import', 'group_sessions.import', 'mother_to_mother.import', 'individual_counseling.import', 'follow_up_children.import',
            'meal_report.view', 'meal_report.export',
            'children.refer',
        ],
        'Data Entry' => [
            'children.view', 'children.create', 'children.edit',
            'pregnant.view', 'pregnant.create', 'pregnant.edit',
            'group_sessions.view', 'group_sessions.create', 'group_sessions.edit',
            'mother_to_mother.view', 'mother_to_mother.create', 'mother_to_mother.edit',
            'individual_counseling.view', 'individual_counseling.create', 'individual_counseling.edit',
            'follow_up_children.view', 'follow_up_children.create', 'follow_up_children.edit',
            'children.import', 'pregnant.import', 'group_sessions.import', 'mother_to_mother.import', 'individual_counseling.import', 'follow_up_children.import',
            'children.refer',
        ],
        'Viewer' => [
            'children.view', 'children.export',
            'pregnant.view', 'pregnant.export',
            'group_sessions.view', 'group_sessions.export',
            'mother_to_mother.view', 'mother_to_mother.export',
            'individual_counseling.view', 'individual_counseling.export',
            'follow_up_children.view', 'follow_up_children.export',
        ],
        'M&E' => [
            'group_sessions.view', 'group_sessions.export',
            'individual_counseling.view', 'individual_counseling.export',
            'follow_up_children.view', 'follow_up_children.export',
            'meal_report.view', 'meal_report.export',
        ],
    ];

    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Super Admin gets everything via Gate::before
        Role::firstOrCreate(['name' => 'Super Admin']);

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::firstOrCreate(['name' => $roleName])->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
