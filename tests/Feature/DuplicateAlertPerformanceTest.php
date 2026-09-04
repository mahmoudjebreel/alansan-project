<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Models\Child;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The duplicate-visit alert has to open, and its "fetch the data" answer has to
 * apply, without the round trip costing a query per field.
 *
 * Both are single user actions on the busiest screen in the system: typing a
 * child ID that already exists, and then accepting the prefill. Anything that
 * scales with the number of form fields shows up here as a query count in the
 * hundreds.
 */
class DuplicateAlertPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * @return array<int, string>
     */
    private function queriesDuring(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    public function test_raising_the_duplicate_alert_is_a_handful_of_queries(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);

        $component = Livewire::test(CreateChild::class);

        // set() rather than fillForm(): the alert hangs off afterStateUpdated,
        // which only a state change on the field itself fires.
        $queries = $this->queriesDuring(function () use ($component): void {
            $component->set('data.child_id', '123456789');
        });

        $component->assertDispatched('show-duplicate-visit-alert');

        $this->assertLessThan(
            25,
            count($queries),
            'Opening the duplicate alert took ' . count($queries) . " queries:\n"
                . implode("\n", array_slice($queries, 0, 20)),
        );
    }

    /**
     * The field re-renders only itself and the visit type it derives. Both must
     * still be right - a partial render that leaves the visit type stale is
     * worse than the slow full render it replaced.
     */
    public function test_the_partial_render_still_updates_the_derived_visit_type(): void
    {
        Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 130,
            'visit_type' => 'new',
        ]);

        Livewire::test(CreateChild::class)
            ->set('data.child_id', '123456789')
            ->assertDispatched('show-duplicate-visit-alert')
            // Same reading as the previous visit: no deterioration, so this
            // stays inside the same follow-up loop.
            ->set('data.muac_mm', 130)
            ->assertSet('data.visit_type', 'follow_up');
    }

    public function test_a_deteriorating_reading_still_reads_as_a_new_admission(): void
    {
        Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 130,
            'visit_type' => 'new',
        ]);

        Livewire::test(CreateChild::class)
            ->set('data.child_id', '123456789')
            ->set('data.muac_mm', 110)
            ->assertSet('data.visit_type', 'new');
    }

    public function test_accepting_the_prefill_does_not_cost_a_query_per_field(): void
    {
        $previous = Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);

        $component = Livewire::test(CreateChild::class);

        $queries = $this->queriesDuring(function () use ($component, $previous): void {
            $component->call('fillChildDataFromAlert', [
                'child_id' => $previous->child_id,
                'name' => $previous->name,
                'sex' => $previous->sex,
                'governorate' => $previous->governorate,
            ]);
        });

        $this->assertLessThan(
            25,
            count($queries),
            'Applying the prefill took ' . count($queries) . " queries:\n"
                . implode("\n", array_slice($queries, 0, 20)),
        );
    }

    public function test_the_duplicate_lookup_reads_one_row_not_the_whole_history(): void
    {
        // Twenty visits for the same child; the alert needs only the latest.
        Child::factory()->count(20)->create(['child_id' => '123456789']);

        $queries = $this->queriesDuring(function (): void {
            \App\Support\ChildDuplicateChecker::latestActiveVisit('123456789');
        });

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('limit 1', strtolower($queries[0]));
    }
}
