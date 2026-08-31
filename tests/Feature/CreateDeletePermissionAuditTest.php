<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\ListPregnantLactatingWomen;
use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final audit of the Create and Delete (soft delete) actions on the Children
 * and Pregnant/Lactating Women tables.
 *
 * Each action is asserted on both levels the brief asks for:
 *
 *  - UI:      the button must not render for a user without the permission.
 *  - Backend: the same user attempting the action directly must be refused.
 *
 * "Directly" is simulated the way a real bypass happens: by posting the raw
 * Livewire `mountAction` call that the button would otherwise have sent, and
 * by opening the Create page URL by hand. Neither goes through the rendered
 * button, so a hidden button alone cannot be what stops them.
 */
class CreateDeletePermissionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The two tables under audit: resource, list page, model, create
     * permission, delete permission, database table.
     *
     * @return array<string, array{0: class-string, 1: class-string, 2: class-string, 3: string, 4: string, 5: string}>
     */
    public static function tables(): array
    {
        return [
            'children' => [
                ChildResource::class,
                ListChildren::class,
                Child::class,
                'children.create',
                'children.delete',
                'children',
            ],
            'pregnant lactating women' => [
                PregnantLactatingWomanResource::class,
                ListPregnantLactatingWomen::class,
                PregnantLactatingWoman::class,
                'pregnant.create',
                'pregnant.delete',
                'pregnant_lactating_women',
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
    // 3. The permission matrix itself
    // -----------------------------------------------------------------

    /**
     * Create/Delete grants on these two modules, read back out of
     * role_has_permissions, must be exactly what the programme expects - no
     * stray extra grant and nothing missing.
     */
    public function test_every_role_carries_exactly_the_expected_create_and_delete_permissions(): void
    {
        $audited = ['children.create', 'children.delete', 'pregnant.create', 'pregnant.delete'];

        $expected = [
            // Super Admin's rows are synced separately by
            // SuperAdminPermissionsSeeder; asserted in its own test below.
            'Admin' => ['children.create', 'children.delete', 'pregnant.create', 'pregnant.delete'],
            'Data Entry' => ['children.create', 'pregnant.create'],
            'Viewer' => [],
            'M&E' => [],
        ];

        foreach ($expected as $role => $granted) {
            $actual = Role::findByName($role)
                ->permissions
                ->pluck('name')
                ->intersect($audited)
                ->sort()
                ->values()
                ->all();

            sort($granted);

            $this->assertSame(
                $granted,
                $actual,
                "Role [{$role}] does not carry exactly the expected Create/Delete permissions.",
            );
        }
    }

    public function test_super_admin_holds_every_create_and_delete_permission_once_seeded(): void
    {
        $this->seed(SuperAdminPermissionsSeeder::class);

        $granted = Role::findByName('Super Admin')->permissions->pluck('name');

        foreach (['children.create', 'children.delete', 'pregnant.create', 'pregnant.delete'] as $permission) {
            $this->assertContains($permission, $granted, "Super Admin is missing [{$permission}].");
        }
    }

    public function test_super_admin_sees_and_may_use_both_buttons_on_both_tables(): void
    {
        $this->seed(SuperAdminPermissionsSeeder::class);

        $superAdmin = $this->userWithRole('Super Admin');
        $this->actingAs($superAdmin);

        foreach (static::tables() as [$resource, $listPage, $model, , , $table]) {
            $record = $model::factory()->create();

            $this->assertTrue($resource::canCreate());
            $this->assertTrue($resource::canDelete($record));

            Livewire::actingAs($superAdmin)
                ->test($listPage)
                ->assertActionVisible(CreateAction::class)
                ->assertTableActionVisible(DeleteAction::class, $record)
                ->callTableAction(DeleteAction::class, $record);

            $this->assertSoftDeleted($table, ['id' => $record->id]);
        }
    }

    // -----------------------------------------------------------------
    // 4a. Viewer: no buttons, and refusal on every direct attempt
    // -----------------------------------------------------------------

    #[DataProvider('tables')]
    public function test_a_viewer_sees_no_create_or_delete_button(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        Livewire::actingAs($viewer)
            ->test($listPage)
            ->assertActionHidden(CreateAction::class)
            ->assertTableActionHidden(DeleteAction::class, $record)
            ->assertTableBulkActionHidden(DeleteBulkAction::class);
    }

    #[DataProvider('tables')]
    public function test_the_policy_denies_a_viewer_create_and_delete(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $this->actingAs($viewer);

        // Permission level.
        $this->assertFalse($viewer->can($createPermission));
        $this->assertFalse($viewer->can($deletePermission));

        // Policy level - the ability the Filament action authorises against.
        $this->assertTrue(Gate::inspect('create', $model)->denied());
        $this->assertTrue(Gate::inspect('delete', $record)->denied());

        // Resource level - what the Create/Edit page guards abort on.
        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canDelete($record));
    }

    #[DataProvider('tables')]
    public function test_a_viewer_opening_the_create_page_by_hand_gets_a_403(
        string $resource,
    ): void {
        $viewer = $this->userWithRole('Viewer');

        $this->actingAs($viewer)
            ->get($resource::getUrl('create'))
            ->assertForbidden();
    }

    #[DataProvider('tables')]
    public function test_a_viewer_forging_the_create_action_request_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $viewer = $this->userWithRole('Viewer');

        $component = Livewire::actingAs($viewer)->test($listPage);

        // The exact Livewire payload the Create button would have sent.
        $component->call('mountAction', 'create', [], []);

        $this->assertSame(
            [],
            $component->instance()->mountedActions,
            'The Create action must refuse to mount for a Viewer.',
        );

        $component->call('callMountedAction');

        $this->assertDatabaseCount($table, 0);
    }

    #[DataProvider('tables')]
    public function test_a_viewer_forging_the_delete_action_request_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $component = Livewire::actingAs($viewer)->test($listPage);

        // The exact Livewire payload the row Delete button would have sent.
        $component->call('mountAction', 'delete', [], [
            'table' => true,
            'recordKey' => $record->getKey(),
        ]);

        $this->assertSame(
            [],
            $component->instance()->mountedActions,
            'The Delete action must refuse to mount for a Viewer.',
        );

        $component->call('callMountedAction');

        $this->assertDatabaseHas($table, ['id' => $record->id, 'deleted_at' => null]);
    }

    #[DataProvider('tables')]
    public function test_a_viewer_forging_the_bulk_delete_request_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $component = Livewire::actingAs($viewer)->test($listPage);

        $component->set('selectedTableRecords', [(string) $record->getKey()]);
        $component->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);

        $this->assertSame(
            [],
            $component->instance()->mountedActions,
            'The bulk Delete action must refuse to mount for a Viewer.',
        );

        $component->call('callMountedAction');

        $this->assertDatabaseHas($table, ['id' => $record->id, 'deleted_at' => null]);
    }

    #[DataProvider('tables')]
    public function test_authorising_a_viewer_delete_through_the_gate_throws(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $viewer = $this->userWithRole('Viewer');
        $record = $model::factory()->create();

        $this->actingAs($viewer);

        try {
            Gate::authorize('delete', $record);

            $this->fail('The policy allowed a Viewer to delete.');
        } catch (AuthorizationException $exception) {
            // The denial is what the HTTP layer turns into a 403.
            $response = app(ExceptionHandler::class)->render(request(), $exception);

            $this->assertSame(403, $response->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // 4b. Data Entry: may create, may not delete
    // -----------------------------------------------------------------

    #[DataProvider('tables')]
    public function test_data_entry_sees_create_but_not_delete(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');
        $record = $model::factory()->create();

        Livewire::actingAs($dataEntry)
            ->test($listPage)
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionHidden(DeleteAction::class, $record)
            ->assertTableBulkActionHidden(DeleteBulkAction::class);
    }

    #[DataProvider('tables')]
    public function test_data_entry_may_open_the_create_page(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');

        $this->assertTrue($dataEntry->can($createPermission));

        $this->actingAs($dataEntry);

        $this->assertTrue($resource::canCreate());
        $this->assertTrue(Gate::inspect('create', $model)->allowed());

        $this->get($resource::getUrl('create'))->assertSuccessful();
    }

    #[DataProvider('tables')]
    public function test_data_entry_forging_the_delete_request_is_refused(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $dataEntry = $this->userWithRole('Data Entry');
        $record = $model::factory()->create();

        $this->actingAs($dataEntry);

        $this->assertFalse($dataEntry->can($deletePermission));
        $this->assertTrue(Gate::inspect('delete', $record)->denied());
        $this->assertFalse($resource::canDelete($record));

        $component = Livewire::actingAs($dataEntry)->test($listPage);

        $component->call('mountAction', 'delete', [], [
            'table' => true,
            'recordKey' => $record->getKey(),
        ]);

        $this->assertSame(
            [],
            $component->instance()->mountedActions,
            'The Delete action must refuse to mount for Data Entry.',
        );

        $component->call('callMountedAction');

        $this->assertDatabaseHas($table, ['id' => $record->id, 'deleted_at' => null]);
    }

    // -----------------------------------------------------------------
    // 4c. Admin: sees both buttons and both actually work
    // -----------------------------------------------------------------

    #[DataProvider('tables')]
    public function test_an_admin_sees_both_buttons(
        string $resource,
        string $listPage,
        string $model,
    ): void {
        $admin = $this->userWithRole('Admin');
        $record = $model::factory()->create();

        Livewire::actingAs($admin)
            ->test($listPage)
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionVisible(DeleteAction::class, $record)
            ->assertTableBulkActionVisible(DeleteBulkAction::class);
    }

    #[DataProvider('tables')]
    public function test_an_admin_may_open_the_create_page(
        string $resource,
    ): void {
        $admin = $this->userWithRole('Admin');

        $this->actingAs($admin)
            ->get($resource::getUrl('create'))
            ->assertSuccessful();
    }

    #[DataProvider('tables')]
    public function test_an_admin_can_soft_delete_a_record(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $admin = $this->userWithRole('Admin');
        $record = $model::factory()->create();

        Livewire::actingAs($admin)
            ->test($listPage)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertSoftDeleted($table, ['id' => $record->id]);
    }

    #[DataProvider('tables')]
    public function test_an_admin_can_bulk_soft_delete_a_record(
        string $resource,
        string $listPage,
        string $model,
        string $createPermission,
        string $deletePermission,
        string $table,
    ): void {
        $admin = $this->userWithRole('Admin');
        $record = $model::factory()->create();

        Livewire::actingAs($admin)
            ->test($listPage)
            ->callTableBulkAction(DeleteBulkAction::class, [$record]);

        $this->assertSoftDeleted($table, ['id' => $record->id]);
    }
}
