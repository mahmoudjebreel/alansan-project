<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a recorded follow-up visit was attended or missed.
 *
 * A follow-up child is seen weekly, and a child who misses a visit is a real
 * event in the treatment history: the programme counts two consecutive missed
 * visits as a defaulter. The record had no way to say it. A missed visit was
 * either left out - and the sequence read as if the child had never been
 * absent - or written down as if it had happened, with a reading nobody took.
 *
 * One column: 'attended' or 'missed'. It defaults to 'attended', so every
 * visit already on file keeps meaning exactly what it meant - each of them
 * was a visit that happened. Nothing is invented on the way in: a missed
 * visit is a row only when somebody records it as one, and the system never
 * writes one on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_child_visits', function (Blueprint $table): void {
            $table->string('status', 16)->default('attended')->after('fi');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_child_visits', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
