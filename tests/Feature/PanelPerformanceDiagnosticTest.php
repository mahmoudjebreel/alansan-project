<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Pages\Trash;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What every screen costs, measured rather than guessed at.
 *
 * The question this answers is not "does the panel feel fast on a laptop with
 * forty rows in it" - it always does - but "does anything here get slower as
 * the data grows". That is a question about query counts, not milliseconds: a
 * page that runs a fixed number of queries stays usable at a hundred thousand
 * records, and a page that runs one query per row does not, however quick it
 * looks today.
 *
 * So each check below loads a screen at one data size and again at four times
 * that size, and holds the query count to the smaller figure. Wall-clock times
 * are printed alongside for orientation only - this runs on in-memory SQLite,
 * which is faster than the MySQL the panel actually runs on, and no assertion
 * depends on them.
 */
class PanelPerformanceDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    /** Rows seeded per module for the baseline pass. */
    private const BASE_ROWS = 40;

    /** @var array<int, array{screen: string, queries: int, ms: float, rows: int}> */
    private static array $report = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $user = User::factory()->create(['name' => 'قائس الأداء']);
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * Seed one batch of every module.
     */
    private function seedModules(int $count): void
    {
        Child::factory()->count($count)->create();
        PregnantLactatingWoman::factory()->count($count)->create();
        GroupSession::factory()->count($count)->create();
        MotherToMotherSession::factory()->count($count)->create();
        IndividualCounseling::factory()->count($count)->create();
        FollowUpChild::factory()->count($count)->create();
    }

    /**
     * Run a callback with the queries it issues counted and timed.
     *
     * @return array{queries: int, ms: float, sql: array<int, string>}
     */
    private function measure(callable $callback): array
    {
        $sql = [];

        DB::flushQueryLog();
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $started = microtime(true);
        $callback();
        $ms = (microtime(true) - $started) * 1000;

        return ['queries' => count($sql), 'ms' => $ms, 'sql' => $sql];
    }

    private function record(string $screen, int $rows, array $measured): void
    {
        self::$report[] = [
            'screen' => $screen,
            'rows' => $rows,
            'queries' => $measured['queries'],
            'ms' => $measured['ms'],
        ];
    }

    /**
     * The panel URLs a user can actually open.
     *
     * @return array<string, string>
     */
    private function screens(): array
    {
        return [
            'Dashboard' => '/admin',
            'Children (list)' => ChildResource::getUrl('index'),
            'Children (create)' => ChildResource::getUrl('create'),
            'Pregnant women' => \App\Filament\Resources\PregnantLactatingWomanResource::getUrl('index'),
            'Group sessions' => \App\Filament\Resources\GroupSessionResource::getUrl('index'),
            'Mother to mother' => \App\Filament\Resources\MotherToMotherResource::getUrl('index'),
            'Individual counseling' => \App\Filament\Resources\IndividualCounselingResource::getUrl('index'),
            'Follow up children' => \App\Filament\Resources\FollowUpChildResource::getUrl('index'),
            'Users' => \App\Filament\Resources\UserResource::getUrl('index'),
            'Roles' => \App\Filament\Resources\RoleResource::getUrl('index'),
            'Trash' => Trash::getUrl(),
            'Backups' => \App\Filament\Pages\Backups::getUrl(),
            'Cache management' => \App\Filament\Pages\CacheManagement::getUrl(),
            'Activity log' => \App\Filament\Pages\ActivityLogPage::getUrl(),
            'Notification log' => \App\Filament\Pages\NotificationLogPage::getUrl(),
            'Notification settings' => \App\Filament\Pages\NotificationSettingsPage::getUrl(),
            'General settings' => \App\Filament\Pages\ManageSettings::getUrl(),
            'MEAL report' => \App\Filament\Pages\MealReport::getUrl(),
        ];
    }

    // =================================================================
    // Every screen opens, and none of them costs a query per row
    // =================================================================

    public function test_every_screen_opens_and_its_cost_does_not_grow_with_the_data(): void
    {
        $this->seedModules(self::BASE_ROWS);

        $baseline = [];

        foreach ($this->screens() as $name => $url) {
            $measured = $this->measure(function () use ($url): void {
                $this->get($url)->assertOk();
            });

            $baseline[$name] = $measured['queries'];
            $this->record($name, self::BASE_ROWS, $measured);
        }

        // Four times the data. A screen that reads its rows in bulk costs the
        // same; one that walks them costs four times as much.
        $this->seedModules(self::BASE_ROWS * 3);

        foreach ($this->screens() as $name => $url) {
            $measured = $this->measure(function () use ($url): void {
                $this->get($url)->assertOk();
            });

            $this->record($name . ' @4x', self::BASE_ROWS * 4, $measured);

            // A small allowance: pagination counts and a settings read can
            // legitimately differ by a query or two between page loads.
            $this->assertLessThanOrEqual(
                $baseline[$name] + 3,
                $measured['queries'],
                "[{$name}] went from {$baseline[$name]} queries to {$measured['queries']} when the data grew "
                    . 'fourfold - that is a query per row, and it will not survive a real dataset.',
            );
        }
    }

    // =================================================================
    // Deleting, restoring, and deleting for good
    // =================================================================

    public function test_the_trash_restores_and_permanently_deletes(): void
    {
        $this->seedModules(self::BASE_ROWS);

        $child = Child::factory()->create(['name' => 'طفل السلة', 'child_id' => '999888777']);
        $child->delete();

        $this->assertSame(1, Child::onlyTrashed()->count());

        // Restore: the record comes back into its own listing.
        $component = Livewire::test(Trash::class);
        $restored = $this->measure(function () use ($component, $child): void {
            $component->call('restore', 'child', $child->getKey());
        });

        $this->assertSame(0, Child::onlyTrashed()->count());
        $this->assertNotNull(Child::find($child->getKey()));
        $this->record('Trash: restore', self::BASE_ROWS, $restored);

        // Delete for good: it leaves the database entirely.
        $child->delete();
        $forced = $this->measure(function () use ($component, $child): void {
            $component->call('forceDelete', 'child', $child->getKey());
        });

        $this->assertNull(Child::withTrashed()->find($child->getKey()));
        $this->record('Trash: delete for good', self::BASE_ROWS, $forced);
    }

    /**
     * The trash reads one page, not the whole bin.
     *
     * Query count alone did not catch the original problem: it was flat while
     * every one of those queries read every trashed row there was. What has to
     * stay bounded is the work inside them, and the visible edge of that is the
     * binding count - the page reads its rows by key, so no statement may carry
     * more keys than a page holds.
     */
    public function test_the_trash_reads_only_the_page_it_shows(): void
    {
        // Bulk, not 600 model deletes: what is measured here is how the page
        // reads the trash, not how the rows got into it.
        Child::factory()->count(120)->create();
        Child::query()->update(['deleted_at' => now()]);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            $bindings[] = count($query->bindings);
        });

        $before = memory_get_peak_usage();
        $this->get(Trash::getUrl())->assertOk();
        $grew = (memory_get_peak_usage() - $before) / 1024 / 1024;

        $widest = max($bindings);

        $this->assertLessThanOrEqual(
            (new Trash)->perPage + 10,
            $widest,
            "A statement carried {$widest} bindings for a page of " . (new Trash)->perPage
                . ' rows - the page is reading the whole trash again.',
        );

        $this->assertLessThan(
            12,
            $grew,
            sprintf('Rendering the trash over 120 deleted records grew memory by %.1f MB.', $grew),
        );

        $this->record('Trash (120 deleted)', 120, ['queries' => count($bindings), 'ms' => 0.0]);
    }

    public function test_the_trash_listing_does_not_query_once_per_deleted_record(): void
    {
        // The deleted-by column comes from the activity log, which is the
        // obvious place for a query per row to hide.
        Child::factory()->count(10)->create()->each(fn (Child $c) => $c->delete());

        $small = $this->measure(function (): void {
            $this->get(Trash::getUrl())->assertOk();
        });

        Child::factory()->count(30)->create()->each(fn (Child $c) => $c->delete());

        $large = $this->measure(function (): void {
            $this->get(Trash::getUrl())->assertOk();
        });

        $this->record('Trash (10 deleted)', 10, $small);
        $this->record('Trash (40 deleted)', 40, $large);

        $this->assertLessThanOrEqual(
            $small['queries'] + 2,
            $large['queries'],
            'The trash listing runs a query per deleted record.',
        );
    }

    // =================================================================
    // The duplicate alert, and pulling the previous visit into the form
    // =================================================================

    public function test_raising_the_duplicate_alert_and_pulling_the_data_back_are_both_cheap(): void
    {
        $this->seedModules(self::BASE_ROWS);

        $previous = Child::factory()->create([
            'child_id' => '123456789',
            'name' => 'طفل مكرر',
            'muac_mm' => 130,
        ]);

        $component = Livewire::test(CreateChild::class);

        // Entering the child ID: the alert has to be raised off a handful of
        // queries, not off a scan of the module.
        $raising = $this->measure(function () use ($component): void {
            $component->set('data.child_id', '123456789');
        });

        $component->assertDispatched('show-duplicate-visit-alert');
        $this->record('Duplicate alert raised', self::BASE_ROWS, $raising);

        $this->assertLessThan(
            15,
            $raising['queries'],
            'Raising the duplicate alert cost ' . $raising['queries'] . ' queries.',
        );

        // "جلب البيانات": the previous visit is pulled into the form. This used
        // to be a query per field.
        $pulling = $this->measure(function () use ($component, $previous): void {
            $component->call('fillChildDataFromAlert', ['data' => [
                'child_id' => $previous->child_id,
                'name' => $previous->name,
                'phone_number' => $previous->phone_number,
                'sex' => $previous->sex,
                'governorate' => $previous->governorate,
            ]]);
        });

        $this->record('Duplicate alert: fetch data', self::BASE_ROWS, $pulling);

        $this->assertLessThan(
            15,
            $pulling['queries'],
            'Pulling the previous visit into the form cost ' . $pulling['queries'] . ' queries.',
        );

        // The form actually carries the data afterwards.
        $component->assertSet('data.name', 'طفل مكرر');
    }

    public function test_entering_a_measurement_does_not_re_render_the_whole_form(): void
    {
        $this->seedModules(self::BASE_ROWS);

        $component = Livewire::test(CreateChild::class);

        $measured = $this->measure(function () use ($component): void {
            $component->set('data.muac_mm', 110);
        });

        $this->record('MUAC entered', self::BASE_ROWS, $measured);

        $component->assertSet('data.fi', 'SAM');

        $this->assertLessThan(
            15,
            $measured['queries'],
            'Entering a MUAC cost ' . $measured['queries'] . ' queries.',
        );
    }

    // =================================================================
    // The dialogs are wired on the pages that raise them
    // =================================================================

    public function test_every_sweetalert_the_panel_relies_on_is_wired(): void
    {
        $html = $this->get(ChildResource::getUrl('create'))->assertOk()->getContent();

        // The library, and the helpers every dialog is built from.
        $this->assertStringContainsString('sweetalert2.all.min.js', $html);
        $this->assertStringContainsString('window.dashboardConfirm', $html);
        $this->assertStringContainsString('window.confirmAction', $html);

        // The five dialogs, by the event or hook each one hangs off.
        $this->assertStringContainsString('show-duplicate-visit-alert', $html);
        $this->assertStringContainsString('show-group-session-duplicate-alert', $html);
        $this->assertStringContainsString('show-follow-up-discharge-alert', $html);
        $this->assertStringContainsString('data-muac-referral', $html);
        $this->assertStringContainsString('addEventListener("submit"', $html);

        // Nothing shipped as an uncompiled Blade directive: that is what
        // silently broke every handler on the trash page.
        $this->assertStringNotContainsString('@js(', $html);

        $trash = $this->get(Trash::getUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('@js(', $trash);
        $this->assertStringNotContainsString('wire:confirm', $trash);
    }

    // =================================================================
    // The caches that make the panel quick, buildable without a terminal
    // =================================================================

    /**
     * One unserialisable route anywhere makes the whole table uncacheable, and
     * nothing says so until somebody runs `route:cache` - which on this
     * deployment nobody can, because there is no terminal on that server. So
     * the same preparation the command performs is done here instead.
     *
     * Deliberately not "no route is a closure": the framework registers a few
     * of its own (the /up health check, the local-disk storage routes) and
     * they serialise perfectly well. What matters is whether the command would
     * succeed, so that is what is asked. prepareForSerialization() compiles the
     * route in memory and writes nothing.
     */
    public function test_the_route_table_can_be_cached(): void
    {
        $blocked = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            try {
                $route->prepareForSerialization();
            } catch (\Throwable $e) {
                $blocked[] = $route->uri() . ' (' . $e->getMessage() . ')';
            }
        }

        $this->assertSame(
            [],
            $blocked,
            "These routes make the route table uncacheable:
  " . implode("
  ", $blocked),
        );
    }

    public function test_the_cache_page_offers_a_build_for_each_cache_that_speeds_the_panel_up(): void
    {
        $types = \App\Filament\Pages\CacheManagement::buildTypes();

        $this->assertSame(['config', 'route', 'view'], array_keys($types));

        foreach ($types as $key => $type) {
            $this->assertNotEmpty($type['label'], "[{$key}] has no label.");
            $this->assertNotEmpty($type['description'], "[{$key}] has no description.");
            $this->assertStringEndsWith(':cache', $type['command']);
        }

        // And the page renders them.
        $html = $this->get(\App\Filament\Pages\CacheManagement::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cache.build_now'), $html);
        $this->assertStringNotContainsString('@js(', $html);
    }

    public function test_building_the_caches_reports_what_it_built(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->times(3)
            ->andReturn(0);

        $this->assertTrue(Livewire::test(\App\Filament\Pages\CacheManagement::class)->instance()->buildAll());
    }

    /**
     * A half-built cache is worse than none: a cached route table sitting on a
     * config file that would not compile leaves a panel nobody can log into and
     * no terminal to undo it with.
     */
    public function test_a_failed_build_throws_away_everything_it_had_built(): void
    {
        $calls = [];

        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->andReturnUsing(function (string $command) use (&$calls): int {
                $calls[] = $command;

                // config caches; the route table refuses.
                return $command === 'route:cache' ? 1 : 0;
            });

        $this->assertFalse(Livewire::test(\App\Filament\Pages\CacheManagement::class)->instance()->buildAll());

        // It stopped at the failure, and then cleared all three.
        $this->assertSame(
            ['config:cache', 'route:cache', 'config:clear', 'route:clear', 'view:clear'],
            $calls,
        );
    }

    // =================================================================
    // Report
    // =================================================================

    public static function tearDownAfterClass(): void
    {
        if (self::$report === []) {
            return;
        }

        $lines = [
            '',
            str_repeat('=', 74),
            'PANEL PERFORMANCE — queries and wall time (in-memory SQLite)',
            str_repeat('=', 74),
            sprintf('%-34s %8s %10s %10s', 'SCREEN / ACTION', 'ROWS', 'QUERIES', 'TIME'),
            str_repeat('-', 74),
        ];

        foreach (self::$report as $row) {
            $lines[] = sprintf(
                '%-34s %8d %10d %9.0fms',
                mb_substr($row['screen'], 0, 34),
                $row['rows'],
                $row['queries'],
                $row['ms'],
            );
        }

        $lines[] = str_repeat('=', 74);

        file_put_contents(storage_path('panel-performance.txt'), implode(PHP_EOL, $lines) . PHP_EOL);

        self::$report = [];

        parent::tearDownAfterClass();
    }
}
