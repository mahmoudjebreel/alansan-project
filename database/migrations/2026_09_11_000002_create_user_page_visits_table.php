<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per full page load inside the panel. The browser reports back with
 * the visit token, never with the row id. Telemetry only; pruned after the
 * configured retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_session_id')->constrained('user_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('visit_token', 36)->unique();
            $table->string('route_name', 191)->nullable()->index();
            $table->string('path', 512);
            $table->string('page_kind', 32)->nullable();
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('entered_at')->index();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('heartbeats')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'entered_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_visits');
    }
};
