<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            // Drop unique index to allow follow-up visit records for the same child_id
            $table->dropUnique('children_child_id_unique');
            $table->index('child_id');
        });

        Schema::table('pregnant_lactating_women', function (Blueprint $table) {
            // Drop unique index to allow follow-up visit records for the same mother_id
            $table->dropUnique('pregnant_lactating_women_mother_id_unique');
            $table->index('mother_id');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex(['child_id']);
            $table->unique('child_id');
        });

        Schema::table('pregnant_lactating_women', function (Blueprint $table) {
            $table->dropIndex(['mother_id']);
            $table->unique('mother_id');
        });
    }
};
