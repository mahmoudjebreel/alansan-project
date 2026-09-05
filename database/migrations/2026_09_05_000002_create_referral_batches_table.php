<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per completed Children Excel upload, recorded after the import has
 * already committed.
 *
 * The Referral Centre needs to know which children arrived in "this upload",
 * and the import itself has no notion of a batch. Rather than teach it one -
 * which would mean changing a service that is strict all-or-nothing and is
 * covered by its own tests - the batch is written afterwards from the
 * ExcelActionOccurred event, and records the primary-key window the upload
 * wrote into.
 *
 * Nothing existing reads or writes this table, and an upload that never gets
 * a batch row (a failed listener, a pre-existing upload) still behaves exactly
 * as it did: the Referral Centre simply falls back to listing every eligible
 * child instead of one upload's worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_batches', function (Blueprint $table) {
            $table->id();

            // Who ran the upload. Nullable and without a foreign key: a batch
            // is a historical note and deleting a user must not delete it.
            $table->unsignedBigInteger('user_id')->nullable();

            // The module key of the upload. Only 'children' produces referral
            // candidates today; the column keeps the table honest if that
            // ever stops being true.
            $table->string('module')->default('children');

            $table->unsignedInteger('imported_count');

            // The children.id window this upload wrote. Inclusive both ends.
            $table->unsignedBigInteger('first_record_id');
            $table->unsignedBigInteger('last_record_id');

            $table->timestamps();

            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_batches');
    }
};
