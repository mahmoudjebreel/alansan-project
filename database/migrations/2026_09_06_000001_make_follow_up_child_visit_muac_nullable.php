<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a recorded follow-up visit carry no measurement.
 *
 * A visit that took place without a MUAC being taken is a real event: the
 * child was seen and referred to a hospital, or the tape was not available.
 * The column was NOT NULL, so that visit could not be written at all - the
 * team had to either invent a reading or drop the visit from the record, and
 * both of those lose the truth.
 *
 * The rest of the system was already built for the blank reading and only this
 * column stood in the way. MuacClassifier::classify(null) returns no
 * classification rather than guessing a band, the tables print "Missing"
 * instead of an empty cell, and ReferralCandidates::visitsMissingMuac() exists
 * to list exactly these visits so somebody can go and find the reading - a
 * query that, against a NOT NULL column, could only ever count zero. The
 * Referral Centre card it feeds starts working here.
 *
 * FI stays derived from the MUAC and is already nullable, so an unmeasured
 * visit simply has no FI. No existing row changes: every measurement that has
 * been recorded stays exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_child_visits', function (Blueprint $table): void {
            $table->decimal('muac', 5, 1)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing this cannot invent the readings that were left blank, and
        // MySQL would otherwise turn them into 0.0 - a measurement that reads
        // as the most severe malnutrition there is. Stop instead, and say
        // which visits have to be settled by hand first.
        $unmeasured = DB::table('follow_up_child_visits')->whereNull('muac')->count();

        if ($unmeasured > 0) {
            throw new RuntimeException(
                "Cannot restore the NOT NULL constraint: {$unmeasured} follow-up visit(s) carry no MUAC. "
                . 'Record or clear those visits first.'
            );
        }

        Schema::table('follow_up_child_visits', function (Blueprint $table): void {
            $table->decimal('muac', 5, 1)->nullable(false)->change();
        });
    }
};
