<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            // Add missing indexes for filters/searchable columns used in child list queries.
            $table->index('visit_type');
            $table->index('neighbourhood');
        });

        Schema::table('pregnant_lactating_women', function (Blueprint $table) {
            // Add missing indexes for filters/searchable columns used in pregnant/lactating list queries.
            $table->index('visit_type');
            $table->index('municipality');
            $table->index('neighbourhood');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex(['visit_type']);
            $table->dropIndex(['neighbourhood']);
        });

        Schema::table('pregnant_lactating_women', function (Blueprint $table) {
            $table->dropIndex(['visit_type']);
            $table->dropIndex(['municipality']);
            $table->dropIndex(['neighbourhood']);
        });
    }
};
