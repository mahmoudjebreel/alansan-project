<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_children', function (Blueprint $table) {
            $table->id();
            $table->string('id_number');
            $table->string('child_name');
            $table->enum('sex', ['M', 'F'])->nullable();
            $table->date('dob')->nullable();
            $table->string('age')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('shelter_name')->nullable();
            $table->string('governorate')->default('Gaza');
            $table->string('causes_of_admission')->nullable();
            $table->enum('admitted_with', ['SAM', 'MAM'])->nullable();
            $table->date('admission_date')->nullable();
            $table->string('discharge_date')->nullable();
            $table->enum('discharge_outcome', ['cured', 'defaulted', 'discharge_to_opt', 'discharge_to_other', 'died', 'under_follow_up'])->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('id_number');
            $table->index('child_name');
            $table->index('shelter_name');
            $table->index('admission_date');
            $table->index('discharge_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_children');
    }
};
