<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pregnant_lactating_women', function (Blueprint $table) {
            $table->id();
            
            // Visit Info
            $table->enum('visit_type', ['new', 'follow_up'])->default('new');
            $table->string('full_name_ar');
            $table->string('mother_id')->unique();
            $table->string('phone_number')->nullable();
            $table->boolean('is_pwd')->default(false);
            $table->string('organization');
            $table->string('implementing_partner');
            $table->date('date_of_reporting');
            $table->boolean('is_displaced')->default(false);
            $table->string('screener_profession')->nullable();
            
            // Demographics
            $table->date('date_of_birth')->nullable();
            $table->integer('age_years')->nullable()->comment('Manual age entry');
            $table->enum('status_type', ['pregnant', 'lactating']);
            
            // Anthropometric
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('muac_mm', 5, 1)->nullable();
            $table->string('fi')->nullable();
            $table->boolean('has_oedema')->default(false);
            
            // Location
            $table->string('governorate');
            $table->string('municipality')->nullable();
            $table->string('neighbourhood')->nullable();
            $table->string('location')->nullable();
            $table->string('type_of_site')->nullable();
            
            // Disability & Conditional
            $table->string('disability_type')->nullable()->comment('Type of disability if exists');
            $table->date('newborn_dob')->nullable()->comment('DOB of newborn - only for lactating');
            $table->string('status')->nullable();
            
            // Husband Info
            $table->string('husband_id_number')->nullable();
            $table->string('husband_full_name')->nullable();
            $table->string('husband_phone')->nullable();
            
            // Family
            $table->integer('family_size')->default(1);
            $table->integer('children_count')->default(0);
            $table->boolean('is_family_pwd')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('governorate');
            $table->index('status_type');
            $table->index('is_displaced');
            $table->index('date_of_reporting');
            $table->index('type_of_site');
            $table->index('organization');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pregnant_lactating_women');
    }
};
