<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_child_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_up_child_id')
                ->constrained('follow_up_children')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('visit_number');
            $table->date('visit_date');
            $table->decimal('muac', 5, 1);
            $table->timestamps();

            $table->unique(['follow_up_child_id', 'visit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_child_visits');
    }
};
