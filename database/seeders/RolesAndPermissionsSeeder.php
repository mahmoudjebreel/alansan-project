<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
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

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Super Admin role (gets all permissions via Gate::before)
        Role::firstOrCreate(['name' => 'Super Admin']);

        // Create Admin role
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $adminRole->syncPermissions([
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
        ]);

        // Create Data Entry role
        $dataEntryRole = Role::firstOrCreate(['name' => 'Data Entry']);
        $dataEntryRole->syncPermissions([
            'children.view', 'children.create', 'children.edit',
            'pregnant.view', 'pregnant.create', 'pregnant.edit',
            'group_sessions.view', 'group_sessions.create', 'group_sessions.edit',
            'mother_to_mother.view', 'mother_to_mother.create', 'mother_to_mother.edit',
            'individual_counseling.view', 'individual_counseling.create', 'individual_counseling.edit',
            'follow_up_children.view', 'follow_up_children.create', 'follow_up_children.edit',
            'children.import', 'pregnant.import', 'group_sessions.import', 'mother_to_mother.import', 'individual_counseling.import', 'follow_up_children.import',
            'children.refer',
        ]);

        // Create Viewer role
        $viewerRole = Role::firstOrCreate(['name' => 'Viewer']);
        $viewerRole->syncPermissions([
            'children.view', 'children.export',
            'pregnant.view', 'pregnant.export',
            'group_sessions.view', 'group_sessions.export',
            'mother_to_mother.view', 'mother_to_mother.export',
            'individual_counseling.view', 'individual_counseling.export',
            'follow_up_children.view', 'follow_up_children.export',
        ]);

        // Create M&E role
        $meRole = Role::firstOrCreate(['name' => 'M&E']);
        $meRole->syncPermissions([
            'group_sessions.view', 'group_sessions.export',
            'individual_counseling.view', 'individual_counseling.export',
            'follow_up_children.view', 'follow_up_children.export',
            'meal_report.view', 'meal_report.export',
        ]);
    }
}
