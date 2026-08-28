<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the Group Session and Mother to Mother "category" columns store every
 * value their Create/Edit form actually offers.
 *
 * The forms gained the Grandmothers / Reproductive Age / Male options, but the
 * column enum was never extended, so saving any of them failed with
 * "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'category'".
 *
 * 'lactating' is deliberately kept in the accepted list even though the form no
 * longer offers it: any historical row that already holds it stays readable.
 * (At the time of writing no row in either table uses it.)
 *
 * Both tables share the same column and the same option set, so both are fixed
 * here. No other column is touched.
 */
return new class extends Migration
{
    private const TABLES = ['group_sessions', 'mother_to_mother_sessions'];

    private const VALUES = [
        'grandmothers',
        'reproductive_age',
        'male',
        'caregiver_child_under_6_months',
        'caregiver_child_6_23_months',
        'pregnant',
        'lactating',
    ];

    private const PREVIOUS_VALUES = [
        'caregiver_child_under_6_months',
        'caregiver_child_6_23_months',
        'pregnant',
        'lactating',
    ];

    public function up(): void
    {
        $this->retype(self::VALUES);
    }

    public function down(): void
    {
        // Values added by this migration cannot survive the narrower list.
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereIn('category', ['grandmothers', 'reproductive_age', 'male'])
                ->update(['category' => 'caregiver_child_under_6_months']);
        }

        $this->retype(self::PREVIOUS_VALUES);
    }

    /**
     * Replace the accepted value list of the category column on both tables.
     *
     * @param  array<string>  $values
     */
    private function retype(array $values): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) use ($values): void {
                $table->enum('category', $values)->change();
            });
        }
    }
};
