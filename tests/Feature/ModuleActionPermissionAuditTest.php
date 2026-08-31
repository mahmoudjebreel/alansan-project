<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\GroupSessionResource;
use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Resources\MotherToMotherResource;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Whole-panel audit of every action button on the six data modules.
 *
 * Each action is asserted on both levels:
 *
 *  - UI:      the button must not render for a user without the permission.
 *  - Backend: the same user attempting the action directly must be refused,
 *             so a hidden button is never the only thing in the way.
 *
 * "Directly" is the way a real bypass happens: the raw Livewire `mountAction`
 * payload the button would otherwise have sent, and page URLs opened by hand.
 * Neither goes through the rendered button.
 *
 * The regression that prompted this: Filament reads an ability it cannot
 * resolve - no policy for the model, or a policy without that exact method -
 * as *allowed*. MotherToMotherSession had no discoverable policy (the class
 * was named MotherToMotherPolicy, and the provider mapping it was never
 * registered), so `deleteAny` fell through to that fallback and bulk delete
 * worked for everyone. The two structural tests below are what keep that from
 * coming back.
 */
class ModuleActionPermissionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The six modules: resource, list page, model, permission prefix, table,
     * and whether the table registers a row Delete action of its own.
     *
     * @return array<string, array{0: class-string, 1: class-string, 2: class-string, 3: string, 4: string, 5: bool}>
     */
    public static function modules(): array
    {
        return [
            'children' => [
                ChildResource::class,
                ChildResource\Pages\ListChildren::class,
                Child::class,
                'children',
                'children',
                true,
            ],
            'pregnant lactating women' => [
                PregnantLactatingWomanResource::class,
                PregnantLactatingWomanResource\Pages\ListPregnantLactatingWomen::class,
                PregnantLactatingWoman::class,
                'pregnant',
                'pregnant_lactating_women',
                true,
            ],
            'group sessions' => [
                GroupSessionResource::class,
                GroupSessionResource\Pages\ListGroupSessions::class,
                GroupSession::class,
                'group_sessions',
                'group_sessions',
                false,
            ],
            'mother to mother' => [
                MotherToMotherResource::class,
                MotherToMotherResource\Pages\ListMotherToMotherSessions::class,
                MotherToMotherSession::class,
                'mother_to_mother',
                'mother_to_mother_sessions',
                false,
            ],
            'individual counseling' => [
                IndividualCounselingResource::class,
                IndividualCounselingResource\Pages\ListIndividualCounselings::class,
                IndividualCounseling::class,
                'individual_counseling',
                'individual_counselings',
                false,
            ],
            'follow up children' => [
                FollowUpChildResource::class,
                FollowUpChildResource\Pages\ListFollowUpChildren::class,
                FollowUpChild::class,
                'follow_up_children',
                'follow_up_children',
                false,
            ],
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // -----------------------------------------------------------------
    // 1. The two structural guarantees the rest of the audit rests on
    // -----------------------------------------------------------------

    #[DataProvider('modules')]
    public function test_every_module_model_resolves_a_policy(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $this->assertNotNull(
            Gate::getPolicyFor($model),
            "[{$model}] has no resolvable policy. Filament reads an unresolved ability as allowed, "
            . 'so every action on this module would be open to every user.',
        );
    }

    /**
     * Filament only consults a policy method that exists; a missing one is
     * read as "allowed". The set therefore has to be complete, not just the
     * four obvious CRUD abilities.
     */
    #[DataProvider('modules')]
    public function test_the_policy_answers_every_ability_filament_asks_about(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $policy = Gate::getPolicyFor($model);

        $abilities = [
            'viewAny', 'view', 'create', 'update',
            'delete', 'deleteAny',
            'restore', 'restoreAny',
            'forceDelete', 'forceDeleteAny',
            'replicate', 'reorder',
        ];

        foreach ($abilities as $ability) {
            $this->assertTrue(
                method_exists($policy, $ability),
                $policy::class . " is missing [{$ability}()]; Filament would treat it as allowed.",
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. The permission matrix itself
    // -----------------------------------------------------------------

    /**
     * Every role's grants across the six modules, read back out of
     * role_has_permissions, must be exactly what the programme expects -
     * nothing missing and no stray extra grant.
     */
    public function test_every_role_carries_exactly_the_expected_module_permissions(): void
    {
        $prefixes = ['children', 'pregnant', 'group_sessions', 'mother_to_mother', 'individual_counseling', 'follow_up_children'];
        $actions = ['view', 'create', 'edit', 'delete', 'export', 'import'];

        $audited = [];
        foreach ($prefixes as $prefix) {
            foreach ($actions as $action) {
                $audited[] = $prefix . '.' . $action;
            }
        }

        // Which actions each role is expected to hold on every module.
        $expectedActions = [
            'Admin' => ['view', 'create', 'edit', 'delete', 'export', 'import'],
            'Data Entry' => ['view', 'create', 'edit', 'import'],
            'Viewer' => ['view', 'export'],
        ];

        foreach ($expectedActions as $role => $granted) {
            $expected = [];
            foreach ($prefixes as $prefix) {
                foreach ($granted as $action) {
                    $expected[] = $prefix . '.' . $action;
                }
            }
            sort($expected);

            $actual = Role::findByName($role)
                ->permissions
                ->pluck('name')
                ->intersect($audited)
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $expected,
                $actual,
                "Role [{$role}] does not carry exactly the expected module permissions.",
            );
        }

        // M&E is a reporting role: read and export on three modules only.
        $meExpected = [
            'follow_up_children.export', 'follow_up_children.view',
            'group_sessions.export', 'group_sessions.view',
            'individual_counseling.export', 'individual_counseling.view',
        ];
        sort($meExpected);

        $this->assertSame(
            $meExpected,
            Role::findByName('M&E')->permissions->pluck('name')->intersect($audited)->sort()->values()->all(),
            'Role [M&E] does not carry exactly the expected module permissions.',
        );
    }

    public function test_super_admin_holds_every_module_permission_once_seeded(): void
    {
        $this->seed(SuperAdminPermissionsSeeder::class);

        $granted = Role::findByName('Super Admin')->permissions->pluck('name');

        foreach (static::modules() as [, , , $prefix]) {
            foreach (['view', 'create', 'edit', 'delete', 'export', 'import'] as $action) {
                $this->assertContains("{$prefix}.{$action}", $granted, "Super Admin is missing [{$prefix}.{$action}].");
            }
        }
    }

    // -----------------------------------------------------------------
    // 3. Viewer - view and export only
    // -----------------------------------------------------------------

    #[DataProvider('modules')]
    public function test_a_viewer_sees_no_create_edit_or_delete_button(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
        bool $hasRowDelete,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $component = Livewire::actingAs($viewer)
            ->test($listPage)
            ->assertActionHidden(CreateAction::class)
            ->assertTableActionHidden(EditAction::class, $record)
            ->assertTableBulkActionHidden(DeleteBulkAction::class)
            // Import is a write, and a Viewer holds no *.import.
            ->assertActionHidden('importExcel')
            // What a Viewer *is* allowed to do still works.
            ->assertTableActionVisible(ViewAction::class, $record)
            ->assertActionVisible('exportExcel')
            ->assertActionVisible('exportPdf');

        if ($hasRowDelete) {
            $component->assertTableActionHidden(DeleteAction::class, $record);
        }
    }

    #[DataProvider('modules')]
    public function test_the_resource_and_policy_deny_a_viewer_every_write(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $this->actingAs($viewer);

        foreach (['create', 'edit', 'delete', 'import'] as $action) {
            $this->assertFalse($viewer->can("{$prefix}.{$action}"), "Viewer unexpectedly holds [{$prefix}.{$action}].");
        }

        // Policy level - the abilities the Filament actions authorise against.
        $this->assertTrue(Gate::inspect('create', $model)->denied());
        $this->assertTrue(Gate::inspect('update', $record)->denied());
        $this->assertTrue(Gate::inspect('delete', $record)->denied());
        $this->assertTrue(Gate::inspect('deleteAny', $model)->denied());

        // Resource level - what the Create/Edit page guards abort on.
        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canEdit($record));
        $this->assertFalse($resource::canDelete($record));
        $this->assertFalse($resource::canDeleteAny());

        // A Viewer may still read.
        $this->assertTrue($resource::canViewAny());
        $this->assertTrue($resource::canView($record));
    }

    #[DataProvider('modules')]
    public function test_a_viewer_opening_the_create_or_edit_page_by_hand_gets_a_403(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $this->actingAs($viewer)->get($resource::getUrl('create'))->assertForbidden();
        $this->actingAs($viewer)->get($resource::getUrl('edit', ['record' => $record]))->assertForbidden();

        // Reading is unaffected.
        $this->actingAs($viewer)->get($resource::getUrl('index'))->assertSuccessful();
        $this->actingAs($viewer)->get($resource::getUrl('view', ['record' => $record]))->assertSuccessful();
    }

    #[DataProvider('modules')]
    public function test_a_viewer_forging_a_write_action_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        // Create.
        $component = Livewire::actingAs($viewer)->test($listPage);
        $component->call('mountAction', 'create', [], []);
        $this->assertSame([], $component->instance()->mountedActions, 'Create must refuse to mount for a Viewer.');

        // Row edit.
        $component = Livewire::actingAs($viewer)->test($listPage);
        $component->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => $record->getKey()]);
        $this->assertSame([], $component->instance()->mountedActions, 'Edit must refuse to mount for a Viewer.');

        // Row delete.
        $component = Livewire::actingAs($viewer)->test($listPage);
        $component->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => $record->getKey()]);
        $this->assertSame([], $component->instance()->mountedActions, 'Delete must refuse to mount for a Viewer.');
        $component->call('callMountedAction');

        // Bulk delete - the hole this audit was opened for.
        $component = Livewire::actingAs($viewer)->test($listPage);
        $component->set('selectedTableRecords', [(string) $record->getKey()]);
        $component->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);
        $this->assertSame([], $component->instance()->mountedActions, 'Bulk delete must refuse to mount for a Viewer.');
        $component->call('callMountedAction');

        // Import.
        $component = Livewire::actingAs($viewer)->test($listPage);
        $component->call('mountAction', 'importExcel', [], []);
        $this->assertSame([], $component->instance()->mountedActions, 'Import must refuse to mount for a Viewer.');

        $this->assertDatabaseHas($table, ['id' => $record->id, 'deleted_at' => null]);
        $this->assertDatabaseCount($table, 1);
    }

    // -----------------------------------------------------------------
    // 4. Data Entry - may create, edit and import; may not delete or export
    // -----------------------------------------------------------------

    #[DataProvider('modules')]
    public function test_data_entry_sees_create_and_edit_but_not_delete_or_export(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
        bool $hasRowDelete,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');
        $record = $model::factory()->create();

        $component = Livewire::actingAs($dataEntry)
            ->test($listPage)
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionVisible(EditAction::class, $record)
            ->assertActionVisible('importExcel')
            ->assertTableBulkActionHidden(DeleteBulkAction::class)
            ->assertActionHidden('exportExcel')
            ->assertActionHidden('exportPdf');

        if ($hasRowDelete) {
            $component->assertTableActionHidden(DeleteAction::class, $record);
        }
    }

    #[DataProvider('modules')]
    public function test_data_entry_may_open_the_create_and_edit_pages(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');
        $record = $model::factory()->create();

        $this->actingAs($dataEntry);

        $this->assertTrue($resource::canCreate());
        $this->assertTrue($resource::canEdit($record));

        $this->get($resource::getUrl('create'))->assertSuccessful();
        $this->get($resource::getUrl('edit', ['record' => $record]))->assertSuccessful();
    }

    #[DataProvider('modules')]
    public function test_data_entry_forging_a_delete_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');
        $record = $model::factory()->create();

        $this->actingAs($dataEntry);

        $this->assertFalse($dataEntry->can("{$prefix}.delete"));
        $this->assertTrue(Gate::inspect('delete', $record)->denied());
        $this->assertTrue(Gate::inspect('deleteAny', $model)->denied());
        $this->assertFalse($resource::canDelete($record));
        $this->assertFalse($resource::canDeleteAny());

        $component = Livewire::actingAs($dataEntry)->test($listPage);
        $component->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => $record->getKey()]);
        $this->assertSame([], $component->instance()->mountedActions, 'Delete must refuse to mount for Data Entry.');
        $component->call('callMountedAction');

        $component = Livewire::actingAs($dataEntry)->test($listPage);
        $component->set('selectedTableRecords', [(string) $record->getKey()]);
        $component->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);
        $this->assertSame([], $component->instance()->mountedActions, 'Bulk delete must refuse to mount for Data Entry.');
        $component->call('callMountedAction');

        $this->assertDatabaseHas($table, ['id' => $record->id, 'deleted_at' => null]);
    }

    // -----------------------------------------------------------------
    // 5. Admin and Super Admin - every button, and every button works
    // -----------------------------------------------------------------

    #[DataProvider('modules')]
    public function test_an_admin_sees_every_button_and_may_delete(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
        bool $hasRowDelete,
    ): void {
        $admin = $this->userWithRole('Admin');
        $record = $model::factory()->create();

        $this->actingAs($admin);

        $this->assertTrue($resource::canViewAny());
        $this->assertTrue($resource::canCreate());
        $this->assertTrue($resource::canEdit($record));
        $this->assertTrue($resource::canDelete($record));
        $this->assertTrue($resource::canDeleteAny());

        Livewire::actingAs($admin)
            ->test($listPage)
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionVisible(EditAction::class, $record)
            ->assertTableActionVisible(ViewAction::class, $record)
            ->assertTableBulkActionVisible(DeleteBulkAction::class)
            ->assertActionVisible('exportExcel')
            ->assertActionVisible('exportPdf')
            ->assertActionVisible('importExcel');

        if ($hasRowDelete) {
            Livewire::actingAs($admin)
                ->test($listPage)
                ->assertTableActionVisible(DeleteAction::class, $record)
                ->callTableAction(DeleteAction::class, $record);
        } else {
            Livewire::actingAs($admin)
                ->test($listPage)
                ->callTableBulkAction(DeleteBulkAction::class, [$record]);
        }

        $this->assertSoftDeleted($table, ['id' => $record->id]);
    }

    #[DataProvider('modules')]
    public function test_a_super_admin_sees_every_button_and_may_delete(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
        string $table,
    ): void {
        $this->seed(SuperAdminPermissionsSeeder::class);

        $superAdmin = $this->userWithRole('Super Admin');
        $record = $model::factory()->create();

        $this->actingAs($superAdmin);

        $this->assertTrue($resource::canCreate());
        $this->assertTrue($resource::canEdit($record));
        $this->assertTrue($resource::canDelete($record));
        $this->assertTrue($resource::canDeleteAny());

        Livewire::actingAs($superAdmin)
            ->test($listPage)
            ->assertActionVisible(CreateAction::class)
            ->assertTableBulkActionVisible(DeleteBulkAction::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$record]);

        $this->assertSoftDeleted($table, ['id' => $record->id]);
    }

    // -----------------------------------------------------------------
    // 6. Restore / force delete, on the two tables that offer them
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: class-string, 1: class-string, 2: class-string, 3: string}>
     */
    public static function trashAwareModules(): array
    {
        return [
            'children' => [
                ChildResource::class,
                ChildResource\Pages\ListChildren::class,
                Child::class,
                'children',
            ],
            'pregnant lactating women' => [
                PregnantLactatingWomanResource::class,
                PregnantLactatingWomanResource\Pages\ListPregnantLactatingWomen::class,
                PregnantLactatingWoman::class,
                'pregnant',
            ],
        ];
    }

    #[DataProvider('trashAwareModules')]
    public function test_restore_and_force_delete_follow_the_delete_permission(
        string $resource,
        string $listPage,
        string $model,
        string $prefix,
    ): void {
        $record = $model::factory()->create();
        $record->delete();

        foreach (['Viewer', 'Data Entry'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user);

            $this->assertFalse($resource::canRestore($record), "[{$role}] may restore without {$prefix}.delete.");
            $this->assertFalse($resource::canForceDelete($record), "[{$role}] may force delete without {$prefix}.delete.");
            $this->assertFalse($resource::canRestoreAny());
            $this->assertFalse($resource::canForceDeleteAny());
            $this->assertTrue(Gate::inspect('restore', $record)->denied());
            $this->assertTrue(Gate::inspect('forceDelete', $record)->denied());
        }

        $admin = $this->userWithRole('Admin');
        $this->actingAs($admin);

        $this->assertTrue($resource::canRestore($record));
        $this->assertTrue($resource::canForceDelete($record));
    }

    /**
     * The trashed-state guard on Restore/ForceDelete has to survive the
     * permission guard being added beside it: an authorised user must still
     * not see Restore on a row that is not deleted.
     */
    #[DataProvider('trashAwareModules')]
    public function test_restore_stays_hidden_on_a_live_row_for_an_authorised_user(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $admin = $this->userWithRole('Admin');
        $live = $model::factory()->create();

        Livewire::actingAs($admin)
            ->test($listPage)
            ->assertTableActionHidden(RestoreAction::class, $live)
            ->assertTableActionHidden(ForceDeleteAction::class, $live)
            ->assertTableActionVisible(DeleteAction::class, $live);
    }
}
