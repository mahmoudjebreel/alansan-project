<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the two flat follow-up columns on individual_counselings.
 *
 * `follow_up_visit_date` and `assess_and_analyze` were a single hard-coded
 * follow-up session living on the record itself. Sessions are now rows in
 * individual_counseling_followups, so the flat pair is moved across and only
 * then removed.
 *
 * The move runs before the drop and is verified row by row: if a single record
 * fails to land in the new table the migration aborts with both columns still
 * in place, so nothing can be lost. Soft-deleted records are carried over too.
 *
 * A record that already holds the maximum number of sessions still receives
 * its migrated one — preserving recorded data outranks the six-session cap,
 * which governs data entry rather than history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('individual_counselings', 'follow_up_visit_date')) {
            return;
        }

        $pending = DB::table('individual_counselings')
            ->select('id', 'follow_up_visit_date', 'assess_and_analyze')
            ->where(function ($query): void {
                $query->whereNotNull('follow_up_visit_date')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('assess_and_analyze')->where('assess_and_analyze', '!=', '');
                    });
            })
            ->get();

        $now = now();
        $moved = 0;

        foreach ($pending as $record) {
            // The flat session predates every repeater row, so it sorts first.
            // sort_order is unsigned, so the existing rows move up rather than
            // the new one moving below zero.
            DB::table('individual_counseling_followups')
                ->where('individual_counseling_id', $record->id)
                ->increment('sort_order');

            DB::table('individual_counseling_followups')->insert([
                'individual_counseling_id' => $record->id,
                'sort_order' => 0,
                'follow_up_visit_date' => $record->follow_up_visit_date,
                'assess_and_analyze' => $record->assess_and_analyze,
                'act' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $moved++;
        }

        if ($moved !== $pending->count()) {
            throw new RuntimeException(
                'Aborting: only ' . $moved . ' of ' . $pending->count()
                . ' flat follow-up rows were moved. The columns were left in place.',
            );
        }

        Schema::table('individual_counselings', function (Blueprint $table): void {
            $table->dropColumn(['follow_up_visit_date', 'assess_and_analyze']);
        });
    }

    /**
     * Put the columns back and return the earliest session of each record to
     * them. The session row itself is left alone: it is now the record of
     * truth, and deleting it would be the data loss this migration avoids.
     */
    public function down(): void
    {
        if (Schema::hasColumn('individual_counselings', 'follow_up_visit_date')) {
            return;
        }

        Schema::table('individual_counselings', function (Blueprint $table): void {
            $table->text('assess_and_analyze')->nullable();
            $table->date('follow_up_visit_date')->nullable();
        });

        $seen = [];

        $sessions = DB::table('individual_counseling_followups')
            ->orderBy('individual_counseling_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            // Ordered ascending, so the first row seen per record is its earliest.
            if (isset($seen[$session->individual_counseling_id])) {
                continue;
            }

            $seen[$session->individual_counseling_id] = true;

            DB::table('individual_counselings')
                ->where('id', $session->individual_counseling_id)
                ->update([
                    'follow_up_visit_date' => $session->follow_up_visit_date,
                    'assess_and_analyze' => $session->assess_and_analyze,
                ]);
        }
    }
};
