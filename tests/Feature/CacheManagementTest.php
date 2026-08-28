<?php

namespace Tests\Feature;

use App\Filament\Pages\CacheManagement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CacheManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    // -----------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------

    public function test_only_users_with_the_cache_manage_permission_see_the_page(): void
    {
        $this->actingAsRole('Super Admin');
        $this->assertTrue(CacheManagement::canAccess());

        foreach (['Admin', 'Data Entry', 'Viewer', 'M&E'] as $role) {
            $this->actingAsRole($role);
            $this->assertFalse(
                CacheManagement::canAccess(),
                "The [{$role}] role must not reach the cache management page.",
            );
        }
    }

    public function test_a_user_granted_cache_manage_directly_can_access_the_page(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('cache.manage');
        $this->actingAs($user);

        $this->assertTrue(CacheManagement::canAccess());
    }

    public function test_clearing_a_single_cache_is_refused_without_the_permission(): void
    {
        $this->actingAsRole('Viewer');

        $this->expectException(HttpException::class);

        (new CacheManagement)->clearCache('application');
    }

    public function test_clearing_everything_is_refused_without_the_permission(): void
    {
        $this->actingAsRole('Data Entry');

        $this->expectException(HttpException::class);

        (new CacheManagement)->clearAll();
    }

    public function test_a_guest_cannot_clear_any_cache(): void
    {
        $this->expectException(HttpException::class);

        (new CacheManagement)->clearCache('config');
    }

    /**
     * The page flushes the permission cache, so the guard has to resolve before
     * anything is cleared. Otherwise a user could use the button to drop the
     * cache backing their own (denied) authorization check.
     */
    public function test_an_unauthorized_user_cannot_flush_the_permission_cache(): void
    {
        // Warm the registrar cache so a flush would be observable.
        $registrar = app(PermissionRegistrar::class);
        $registrar->getPermissions();
        $this->assertTrue($registrar->getCacheRepository()->has($registrar->cacheKey));

        $this->actingAsRole('Viewer');

        try {
            (new CacheManagement)->clearCache('permissions');
            $this->fail('A user without cache.manage was able to flush the permission cache.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // The cache is still intact — the guard ran before the flush.
        $this->assertTrue(
            $registrar->getCacheRepository()->has($registrar->cacheKey),
            'The permission cache was flushed despite the request being denied.',
        );
    }

    // -----------------------------------------------------------------
    // Clearing behaviour
    // -----------------------------------------------------------------

    public function test_every_cache_type_clears_and_reports_success(): void
    {
        $this->actingAsRole('Super Admin');

        foreach (CacheManagement::cacheTypes() as $type => $definition) {
            Livewire::test(CacheManagement::class)
                ->call('clearCache', $type)
                ->assertHasNoErrors()
                ->assertNotified('تم مسح ' . $definition['label'] . ' بنجاح');
        }
    }

    /**
     * Blade directives are not compiled inside component attributes, so the
     * confirmation expressions are built in PHP. Guard the rendered output, as
     * a broken Alpine expression only fails in the browser.
     */
    public function test_the_page_renders_a_working_button_for_every_cache_type(): void
    {
        $this->actingAsRole('Super Admin');

        $html = html_entity_decode(Livewire::test(CacheManagement::class)->html(), ENT_QUOTES);

        $this->assertStringContainsString('$wire.call("clearAll")', $html);

        foreach (array_keys(CacheManagement::cacheTypes()) as $type) {
            $this->assertStringContainsString(
                '$wire.call("clearCache", "' . $type . '")',
                $html,
                "The [{$type}] button is missing or its Alpine expression did not compile.",
            );
        }

        // Un-compiled Blade left inside an attribute would break Alpine at runtime.
        $this->assertStringNotContainsString('@js(', $html);
    }

    public function test_an_unknown_cache_type_is_rejected_without_an_error_screen(): void
    {
        $this->actingAsRole('Super Admin');

        $this->assertFalse((new CacheManagement)->clearCache('does-not-exist'));
    }

    public function test_clear_all_runs_every_type_and_reports_one_summary(): void
    {
        $this->actingAsRole('Super Admin');

        Livewire::test(CacheManagement::class)
            ->call('clearAll')
            ->assertHasNoErrors()
            ->assertNotified('تم مسح كل أنواع الكاش بنجاح');

        $this->assertTrue((new CacheManagement)->clearAll());
    }

    public function test_clear_all_covers_the_permission_cache(): void
    {
        $this->actingAsRole('Super Admin');

        $registrar = app(PermissionRegistrar::class);
        $registrar->getPermissions();
        $this->assertTrue($registrar->getCacheRepository()->has($registrar->cacheKey));

        (new CacheManagement)->clearAll();

        $this->assertFalse(
            $registrar->getCacheRepository()->has($registrar->cacheKey),
            'Clear all must flush the spatie permission cache too.',
        );
    }

    // -----------------------------------------------------------------
    // The permission cache scenario this page exists for
    // -----------------------------------------------------------------

    public function test_a_direct_database_permission_change_takes_effect_after_the_flush(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');

        // Warm the cache with the current (denied) state.
        $this->assertFalse($viewer->can('children.delete'));

        // Grant the permission straight in the pivot table, the way a seeder or
        // a manual SQL fix would — spatie never learns about it.
        DB::table(config('permission.table_names.role_has_permissions'))->insert([
            'role_id' => Role::findByName('Viewer')->getKey(),
            'permission_id' => Permission::findByName('children.delete')->getKey(),
        ]);

        // Still stale: a brand new instance keeps reading the cached grants.
        $this->assertFalse(User::find($viewer->getKey())->can('children.delete'));

        $this->actingAsRole('Super Admin');
        $this->assertTrue((new CacheManagement)->clearCache('permissions'));

        // The very next check now reflects the database.
        $this->assertTrue(
            User::find($viewer->getKey())->can('children.delete'),
            'Flushing the permission cache must surface the direct database change immediately.',
        );
    }

    public function test_flushing_the_permission_cache_leaves_roles_and_permissions_intact(): void
    {
        $this->actingAsRole('Super Admin');

        $roles = Role::count();
        $permissions = Permission::count();
        $pivot = DB::table(config('permission.table_names.role_has_permissions'))->count();

        (new CacheManagement)->clearCache('permissions');
        (new CacheManagement)->clearAll();

        $this->assertSame($roles, Role::count());
        $this->assertSame($permissions, Permission::count());
        $this->assertSame($pivot, DB::table(config('permission.table_names.role_has_permissions'))->count());
    }

    public function test_the_cache_manage_permission_is_registered_for_super_admin_only(): void
    {
        $this->assertTrue(Permission::where('name', 'cache.manage')->exists());

        foreach (['Admin', 'Data Entry', 'Viewer', 'M&E'] as $role) {
            $this->assertFalse(
                Role::findByName($role)->hasPermissionTo('cache.manage'),
                "The [{$role}] role must not hold cache.manage.",
            );
        }
    }
}
