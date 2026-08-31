<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The flat-column data migration, exercised against rows that only the old
 * schema could have produced.
 */
class IndividualCounselingFlatMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrator(): object
    {
        return require base_path('database/migrations/2026_08_29_000002_move_individual_counseling_flat_followup_into_sessions.php');
    }

    public function test_flat_rows_are_moved_before_the_columns_are_dropped(): void
    {
        // Rewind to the shape the columns had before the move.
        $this->migrator()->down();

        $this->assertTrue(Schema::hasColumn('individual_counselings', 'follow_up_visit_date'));

        $withFlat = DB::table('individual_counselings')->insertGetId([
            'date' => '2026-07-01', 'child_name' => 'A', 'mother_id_number' => '100000001',
            'mother_name' => 'AM', 'follow_up_visit_date' => '2026-07-20',
            'assess_and_analyze' => 'تقييم مسطّح', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $withBoth = DB::table('individual_counselings')->insertGetId([
            'date' => '2026-07-02', 'child_name' => 'B', 'mother_id_number' => '100000002',
            'mother_name' => 'BM', 'follow_up_visit_date' => '2026-07-05',
            'assess_and_analyze' => 'مسطّح ثانٍ', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('individual_counseling_followups')->insert([
            'individual_counseling_id' => $withBoth, 'sort_order' => 0,
            'follow_up_visit_date' => '2026-08-09', 'assess_and_analyze' => 'جلسة قائمة',
            'act' => 'إجراء', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $empty = DB::table('individual_counselings')->insertGetId([
            'date' => '2026-07-03', 'child_name' => 'C', 'mother_id_number' => '100000003',
            'mother_name' => 'CM', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migrator()->up();

        // The columns are gone only now that the data is elsewhere.
        $this->assertFalse(Schema::hasColumn('individual_counselings', 'follow_up_visit_date'));
        $this->assertFalse(Schema::hasColumn('individual_counselings', 'assess_and_analyze'));

        $moved = DB::table('individual_counseling_followups')->where('individual_counseling_id', $withFlat)->get();
        $this->assertCount(1, $moved);
        $this->assertSame('تقييم مسطّح', $moved[0]->assess_and_analyze);
        $this->assertStringStartsWith('2026-07-20', $moved[0]->follow_up_visit_date);

        // The migrated session sorts ahead of the sessions already recorded.
        $both = DB::table('individual_counseling_followups')
            ->where('individual_counseling_id', $withBoth)->orderBy('sort_order')->get();
        $this->assertCount(2, $both);
        $this->assertSame('مسطّح ثانٍ', $both[0]->assess_and_analyze);
        $this->assertSame('جلسة قائمة', $both[1]->assess_and_analyze);
        $this->assertLessThan($both[1]->sort_order, $both[0]->sort_order);

        // A record with nothing flat to move gains nothing.
        $this->assertSame(0, DB::table('individual_counseling_followups')
            ->where('individual_counseling_id', $empty)->count());
    }
}
