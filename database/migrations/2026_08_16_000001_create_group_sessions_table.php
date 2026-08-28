<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_sessions', function (Blueprint $table) {
            $table->id();
            $table->date('session_date');
            $table->string('session_group_number');
            $table->enum('session_subject', ['bf_support', 'relactation', 'complimentary_feeding', 'other']);
            $table->string('session_subject_other')->nullable();
            $table->enum('locality', ['tal_al_hawa', 'el_saftawi', 'el_nafaq', 'el_shatee', 'karamah']);
            $table->enum('shelter_name', ['mosaab_camp', 'mahabba', 'el_salam', 'el_qoqa']);
            $table->string('id_number');
            $table->string('full_name_ar');
            $table->enum('visit_type', ['new', 'follow_up'])->default('new');
            $table->enum('category', ['caregiver_child_under_6_months', 'caregiver_child_6_23_months', 'pregnant', 'lactating']);
            $table->date('newborn_dob')->nullable();
            $table->boolean('is_pwd')->default(false);
            $table->enum('marital_status', ['married', 'divorced', 'widow', 'separated']);
            $table->string('phone_number')->nullable();
            $table->boolean('has_gsfsh')->default(false);
            $table->boolean('receives_supplementary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['session_date', 'locality']);
            $table->index('shelter_name');
            $table->index('session_subject');
            $table->index('visit_type');
            $table->index('id_number');
            $table->index('full_name_ar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_sessions');
    }
};
