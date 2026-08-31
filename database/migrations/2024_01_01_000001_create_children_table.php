<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            
            // Visit Info
            $table->enum('visit_type', ['new', 'follow_up'])->default('new');
            $table->string('name');
            $table->string('child_id')->unique();
            $table->string('phone_number')->nullable();
            $table->boolean('is_pwd')->default(false);
            $table->string('organization');
            $table->string('implementing_partner');
            $table->date('date_of_reporting');
            $table->boolean('is_displaced')->default(false);
            $table->string('screener_profession')->nullable();
            
            // Child Demographics
            $table->enum('sex', ['male', 'female']);
            $table->date('date_of_birth')->nullable();
            $table->integer('age_months')->nullable()->comment('Manual age entry in months');
            
            // Anthropometric Measurements
            $table->decimal('muac_mm', 5, 1)->nullable();
            $table->string('fi')->nullable()->comment('Food Insecurity indicator');
            $table->boolean('has_oedema')->default(false);
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('whz', 4, 2)->nullable()->comment('Weight-for-Height Z-score');
            
            // Location
            $table->string('governorate');
            $table->string('municipality')->nullable();
            $table->string('neighbourhood')->nullable();
            $table->string('location')->nullable();
            $table->string('type_of_site')->nullable();
            
            // Program Enrollment
            $table->boolean('is_enrolled_bsfp')->default(false);
            $table->boolean('is_sick_last_6_months')->default(false);
            $table->boolean('is_mother_alive')->default(true);
            
            // Mother Information
            $table->string('mother_full_name')->nullable();
            $table->string('mother_id_number')->nullable();
            $table->date('mother_date_of_birth')->nullable();
            $table->integer('mother_age_years')->nullable();
            $table->string('mother_phone')->nullable();
            
            // Father Information
            $table->string('father_full_name')->nullable();
            $table->string('father_id_number')->nullable();
            $table->string('father_phone')->nullable();
            
            // Household Information
            $table->boolean('has_lactating_woman')->default(false);
            $table->boolean('has_pregnant_last_trimester')->default(false);
            $table->integer('children_under_5')->default(0);
            $table->enum('head_of_household_sex', ['male', 'female'])->nullable();
            $table->string('mother_marital_status')->nullable();
            $table->decimal('mother_muac_mm', 5, 1)->nullable();
            $table->boolean('is_mother_malnourished')->default(false);
            
            // Income
            $table->boolean('has_stable_income')->default(false);
            $table->enum('income_source', ['government', 'unrwa', 'other'])->nullable();
            $table->boolean('is_income_below_500')->default(false);
            
            // Family Details
            $table->integer('male_children_under_5')->default(0);
            $table->integer('female_children_under_5')->default(0);
            $table->integer('family_size')->default(1);
            $table->string('current_address')->nullable();
            $table->string('original_address')->nullable();
            
            // Disability
            $table->boolean('has_family_disability')->default(false);
            $table->enum('disability_cause', ['war', 'other'])->nullable();
            $table->text('disability_cause_other')->nullable();
            
            // Conflict Impact
            $table->boolean('has_injured_after_oct7')->default(false);
            $table->integer('injured_count')->nullable();
            $table->boolean('has_unaccompanied_children')->default(false);
            $table->integer('unaccompanied_children_count')->nullable();
            $table->boolean('has_released_children')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('governorate');
            $table->index('municipality');
            $table->index('sex');
            $table->index('is_displaced');
            $table->index('date_of_reporting');
            $table->index('type_of_site');
            $table->index('organization');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
