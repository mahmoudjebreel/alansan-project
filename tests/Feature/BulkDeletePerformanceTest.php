<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\BulkRecordWriter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A bulk delete must cost a number of queries proportional to the number of
 * chunks, not to the number of records.
 *
 * This is the regression that made the module unusable: Filament's stock
 * action loaded every selected row and called delete() on each one, and each
 * of those deletes fired LogsActivity (one activity_log INSERT carrying the
 * whole 68-column row as JSON) and NotifiesSuperAdminOnChange (one queued
 * notification job). Deleting a few thousand rows was tens of thousands of
 * writes inside one HTTP request, and it timed out.
 *
 * The assertions below are budgets, not exact counts: they are loose enough to
 * survive an unrelated query being added somewhere, and tight enough that
 * anyone who reintroduces per-record work will fail them immediately.
 *
 * @see \App\Support\BulkRecordWriter
 * @see \App\Filament\Actions\FastDeleteBulkAction
 */
class BulkDeletePerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Records deleted per test. Large enough that per-record work is obvious
     * in the query count, small enough that the suite stays fast.
     */
    private const RECORDS = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }

    /**
     * Run $callback with query logging on and return the statements it issued.
     *
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

    public function test_a_bulk_soft_delete_does_not_scale_with_the_number_of_records(): void
    {
        $this->actingAsSuperAdmin();

        Child::factory()->count(self::RECORDS)->create();

        $queries = $this->queriesDuring(function (): void {
            $deleted = BulkRecordWriter::softDelete(Child::query());

            $this->assertSame(self::RECORDS, $deleted);
        });

        // One SELECT for the keys, one UPDATE for the single chunk, plus the
        // summary activity entry and the settings reads behind the summary
        // notification. Nothing here may grow with RECORDS.
        $this->assertLessThan(
            20,
            count($queries),
            "Deleting " . self::RECORDS . ' records took ' . count($queries)
                . " queries. A bulk delete must not do per-record work:\n"
                . implode("\n", array_slice($queries, 0, 15)),
        );

        $this->assertSame(self::RECORDS, Child::onlyTrashed()->count());
        $this->assertSame(0, Child::count());
    }

    public function test_a_bulk_delete_writes_one_activity_entry_rather_than_one_per_record(): void
    {
        $this->actingAsSuperAdmin();

        Child::factory()->count(self::RECORDS)->create();

        $before = Activity::count();

        BulkRecordWriter::softDelete(Child::query());

        $written = Activity::count() - $before;

        $this->assertSame(
            1,
            $written,
            "A bulk delete wrote {$written} activity entries; it must write exactly one summary.",
        );

        $summary = Activity::query()->latest('id')->first();

        $this->assertSame('bulk', $summary->log_name);
        $this->assertSame('Child', $summary->properties['module']);
        $this->assertSame(self::RECORDS, $summary->properties['count']);
    }

    public function test_the_listing_page_does_not_query_once_per_row(): void
    {
        $this->actingAsSuperAdmin();

        Child::factory()->count(50)->create();

        $queries = $this->queriesDuring(function (): void {
            Livewire::test(ListChildren::class)->assertSuccessful();
        });

        $this->assertLessThan(
            40,
            count($queries),
            'The Children listing issued ' . count($queries)
                . ' queries for one page. That is an N+1: eager-load what the columns read.',
        );
    }

    public function test_a_bulk_force_delete_clears_related_visits_in_one_statement(): void
    {
        $this->actingAsSuperAdmin();

        FollowUpChild::factory()->count(20)->create()->each(
            fn (FollowUpChild $child) => $child->visits()->create([
                'visit_number' => 1,
                'visit_date' => now()->toDateString(),
                'muac' => 110,
            ]),
        );

        $queries = $this->queriesDuring(function (): void {
            BulkRecordWriter::forceDelete(FollowUpChild::withTrashed());
        });

        $this->assertSame(0, FollowUpChild::withTrashed()->count());
        $this->assertSame(0, FollowUpChildVisit::count(), 'A force delete must take the visits with it.');

        $this->assertLessThan(
            20,
            count($queries),
            'Cascading the visits must be one statement per chunk, not one per parent record.',
        );
    }

    public function test_a_bulk_soft_delete_keeps_the_visits_so_a_restore_is_complete(): void
    {
        $this->actingAsSuperAdmin();

        $child = FollowUpChild::factory()->create();
        $child->visits()->create([
            'visit_number' => 1,
            'visit_date' => now()->toDateString(),
            'muac' => 110,
        ]);

        BulkRecordWriter::softDelete(FollowUpChild::query());

        $this->assertSame(1, FollowUpChildVisit::count(), 'A reversible delete must not destroy the readings.');

        BulkRecordWriter::restore(FollowUpChild::withTrashed());

        $this->assertSame(1, FollowUpChild::count());
        $this->assertSame(1, $child->fresh()->visits()->count());
    }

    public function test_the_filament_bulk_action_uses_the_set_based_path(): void
    {
        $this->actingAsSuperAdmin();

        $records = FollowUpChild::factory()->count(30)->create();

        $queries = $this->queriesDuring(function () use ($records): void {
            Livewire::test(ListFollowUpChildren::class)
                ->callTableBulkAction('delete', $records);
        });

        $this->assertSame(30, FollowUpChild::onlyTrashed()->count());

        $updates = array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'UPDATE'),
        );

        $this->assertLessThanOrEqual(
            2,
            count($updates),
            'The bulk delete issued ' . count($updates)
                . ' UPDATE statements for 30 records; it must issue one per chunk.',
        );
    }
}
