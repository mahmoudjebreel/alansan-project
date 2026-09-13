<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per sitting: from a sign-in to the sign-out, or to the last time
 * the browser was seen. Telemetry only; pruned after the configured retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // SHA-256 of the Laravel session id, never the id itself.
            $table->char('session_hash', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('device_type', 16)->nullable();
            $table->boolean('remember')->default(false);
            $table->timestamp('login_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('logout_at')->nullable();
            $table->string('ended_reason', 16)->nullable();
            $table->unsignedBigInteger('login_activity_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'login_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
