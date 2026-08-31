<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FI (Normal / MAM / SAM) for each recorded follow-up visit.
 *
 * Always derived from the visit's own MUAC by the model, never typed in, so
 * the column is a stored copy of a calculation rather than an input of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_child_visits', function (Blueprint $table) {
            $table->string('fi')->nullable()->after('muac');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_child_visits', function (Blueprint $table) {
            $table->dropColumn('fi');
        });
    }
};
