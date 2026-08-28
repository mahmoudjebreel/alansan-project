<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->integer('children_under_5')->nullable()->default(0)->change();
            $table->integer('male_children_under_5')->nullable()->default(0)->change();
            $table->integer('female_children_under_5')->nullable()->default(0)->change();
            $table->integer('family_size')->nullable()->default(1)->change();
            $table->boolean('has_lactating_woman')->nullable()->default(false)->change();
            $table->boolean('has_pregnant_last_trimester')->nullable()->default(false)->change();
            $table->boolean('has_stable_income')->nullable()->default(false)->change();
            $table->boolean('is_income_below_500')->nullable()->default(false)->change();
            $table->boolean('has_family_disability')->nullable()->default(false)->change();
            $table->boolean('has_injured_after_oct7')->nullable()->default(false)->change();
            $table->boolean('has_unaccompanied_children')->nullable()->default(false)->change();
            $table->boolean('has_released_children')->nullable()->default(false)->change();
            $table->boolean('is_enrolled_bsfp')->nullable()->default(false)->change();
            $table->boolean('is_sick_last_6_months')->nullable()->default(false)->change();
            $table->boolean('is_mother_alive')->nullable()->default(true)->change();
            $table->boolean('is_pwd')->nullable()->default(false)->change();
            $table->boolean('is_displaced')->nullable()->default(false)->change();
            $table->boolean('is_mother_malnourished')->nullable()->default(false)->change();
        });

        Schema::table('pregnant_lactating_women', function (Blueprint $table) {
            $table->integer('family_size')->nullable()->default(1)->change();
            $table->integer('children_count')->nullable()->default(0)->change();
            $table->boolean('is_pwd')->nullable()->default(false)->change();
            $table->boolean('is_displaced')->nullable()->default(false)->change();
            $table->boolean('is_family_pwd')->nullable()->default(false)->change();
        });
    }

    public function down(): void
    {
    }
};
