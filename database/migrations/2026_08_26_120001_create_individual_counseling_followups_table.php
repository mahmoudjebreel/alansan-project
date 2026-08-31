<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeatable follow-up sessions for an Individual Counseling record.
 *
 * One-to-many rather than flat repeated columns, so a record can carry any
 * number of sessions (including none at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individual_counseling_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('individual_counseling_id')
                ->constrained('individual_counselings')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('follow_up_visit_date')->nullable();
            $table->text('assess_and_analyze')->nullable();
            $table->text('act')->nullable();
            $table->timestamps();

            $table->index(['individual_counseling_id', 'sort_order'], 'ic_followups_parent_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individual_counseling_followups');
    }
};
