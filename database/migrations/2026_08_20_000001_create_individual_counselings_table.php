<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individual_counselings', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('health_educator')->nullable();
            // Child data
            $table->string('child_name');
            $table->enum('child_visit_type', ['new', 'follow_up'])->nullable();
            $table->date('child_dob')->nullable();
            $table->integer('age_months')->nullable();
            $table->enum('gender', ['M', 'F'])->nullable();
            $table->string('child_age_lactated')->nullable();
            $table->string('feeding_type')->nullable();
            $table->enum('p_l', ['pregnant', 'lactating'])->nullable();
            $table->decimal('muac', 5, 1)->nullable();
            $table->string('muac_degree')->nullable();
            // Mother data
            $table->string('mother_id_number', 9);
            $table->string('mother_name');
            $table->enum('mother_visit_type', ['new', 'follow_up'])->nullable();
            $table->date('mother_dob')->nullable();
            $table->string('mother_age_years')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('shelter_name')->nullable();
            // Counseling data
            $table->enum('consultation', ['complementary_feeding', 'bf_support', 'other'])->nullable();
            $table->boolean('iycf_form_filled')->nullable();
            $table->enum('status', ['discharged', 'under_follow_up'])->nullable();
            $table->enum('outcome', ['improved', 'dont_improve', 'non_response'])->nullable();
            $table->text('assess')->nullable();
            $table->text('act')->nullable();
            // Pregnancy data
            $table->enum('pregnancy', ['P'])->nullable();
            $table->string('lactating')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('pregnancy_count')->nullable();
            $table->text('assess_and_analyze')->nullable();
            $table->date('follow_up_visit_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index('child_name');
            $table->index('mother_name');
            $table->index('mother_id_number');
            $table->index('shelter_name');
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individual_counselings');
    }
};
