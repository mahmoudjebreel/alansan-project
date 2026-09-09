<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name the kind of admission a Follow Up Child record is.
 *
 * A row in follow_up_children has always been one treatment episode, and a
 * child whose episode closed can be admitted again later. Until now nothing on
 * the second row said that it was a second one: it looked exactly like a first
 * admission, and every report that counted admissions counted it as new.
 *
 * Two nullable columns, and nothing else:
 *
 *   admission_type             'new' or 'readmission'. NULL on every row that
 *                              exists today, and read as 'new' - a first
 *                              admission is what every one of them was.
 *   previous_follow_up_child_id  The closed episode a readmission follows on
 *                              from, so the two can be read together. No
 *                              foreign key on purpose, exactly as with
 *                              source_child_visit_id: episodes soft-delete
 *                              and force-delete independently, and a link
 *                              going stale must never block either.
 *
 * Nothing is back-filled and no existing row is touched. The migration is
 * additive and reversible, and a record with neither value set behaves exactly
 * as it did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_children', function (Blueprint $table): void {
            $table->string('admission_type', 32)->nullable()->after('admitted_with');
            $table->unsignedBigInteger('previous_follow_up_child_id')->nullable()->after('source_child_visit_id');

            $table->index('admission_type');
            $table->index('previous_follow_up_child_id');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_children', function (Blueprint $table): void {
            $table->dropIndex(['admission_type']);
            $table->dropIndex(['previous_follow_up_child_id']);
            $table->dropColumn(['admission_type', 'previous_follow_up_child_id']);
        });
    }
};
