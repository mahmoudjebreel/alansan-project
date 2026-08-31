<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the base (first) visit its own Analyze field.
 *
 * The base visit records Assess and Analyze separately — they are only ever
 * merged from the first follow-up session onwards, where a single
 * "Assess and analyze" field sits next to Act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individual_counselings', function (Blueprint $table): void {
            $table->text('analyze')->nullable()->after('assess');
        });
    }

    public function down(): void
    {
        Schema::table('individual_counselings', function (Blueprint $table): void {
            $table->dropColumn('analyze');
        });
    }
};
