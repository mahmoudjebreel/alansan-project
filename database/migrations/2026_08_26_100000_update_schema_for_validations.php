<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_sessions', function (Blueprint $table) {
            $table->string('receives_supplementary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('group_sessions', function (Blueprint $table) {
            $table->boolean('receives_supplementary')->default(false)->change();
        });
    }
};
