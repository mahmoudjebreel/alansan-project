<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the Pregnant / Lactating Women "status_type" column store the third
 * option the form now offers: حامل + مرضع (pregnant and breastfeeding at the
 * same time).
 *
 * The column was declared as enum('pregnant', 'lactating'), so saving the new
 * value would fail with "Data truncated for column 'status_type'". Only the
 * accepted value list changes here; no row is rewritten and no other column of
 * this table - or of any other module - is touched.
 */
return new class extends Migration
{
    private const VALUES = ['pregnant', 'lactating', 'pregnant_lactating'];

    private const PREVIOUS_VALUES = ['pregnant', 'lactating'];

    public function up(): void
    {
        $this->retype(self::VALUES);
    }

    public function down(): void
    {
        // The value added by this migration cannot survive the narrower list.
        // The combined status is first and foremost a pregnancy, so that is
        // what it falls back to.
        DB::table('pregnant_lactating_women')
            ->where('status_type', 'pregnant_lactating')
            ->update(['status_type' => 'pregnant']);

        $this->retype(self::PREVIOUS_VALUES);
    }

    /**
     * Replace the accepted value list of the status_type column.
     *
     * @param  array<string>  $values
     */
    private function retype(array $values): void
    {
        Schema::table('pregnant_lactating_women', function (Blueprint $table) use ($values): void {
            $table->enum('status_type', $values)->change();
        });
    }
};
