<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentary links between the Children module and the Follow Up Child
 * module, for the two automatic transfers between them.
 *
 * Both are nullable and purely historical: nothing in the listings, the forms
 * or either export reads them, and a record with neither set behaves exactly
 * as it did before. No foreign key constraint is declared on purpose - both
 * modules soft-delete and force-delete independently, and a link going stale
 * must never block a delete or a restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_children', function (Blueprint $table) {
            // The Children visit this admission was raised from, when one exists.
            $table->unsignedBigInteger('source_child_visit_id')->nullable()->after('notes');
            $table->index('source_child_visit_id');
        });

        Schema::table('children', function (Blueprint $table) {
            // The follow-up record whose "Cured" discharge produced this visit.
            $table->unsignedBigInteger('source_follow_up_child_id')->nullable()->after('has_released_children');
            $table->index('source_follow_up_child_id');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_children', function (Blueprint $table) {
            $table->dropIndex(['source_child_visit_id']);
            $table->dropColumn('source_child_visit_id');
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex(['source_follow_up_child_id']);
            $table->dropColumn('source_follow_up_child_id');
        });
    }
};
