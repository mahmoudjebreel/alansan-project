<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the Follow Up Child graduation (discharge) date as a real date.
 *
 * It was a varchar, so the value was whatever text was typed: it could not be
 * sorted or filtered as a date and rendered inconsistently. Every existing
 * value is normalised to Y-m-d before the column is retyped, because MySQL
 * would otherwise reject or zero out anything that is not already a date.
 *
 * Pre-migration audit of this database (2 rows):
 *   id=2  '23-Nov-2019'  -> 2019-11-23
 *   id=1  'سسس'          -> not a date, cleared to NULL (approved)
 *
 * Anything unparseable becomes NULL rather than blocking the migration; the
 * original text is reported by the audit above so it can be restored by hand.
 * No other column is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('follow_up_children')->select('id', 'discharge_date')->get() as $row) {
            $raw = trim((string) $row->discharge_date);

            $normalised = null;

            if ($raw !== '') {
                try {
                    $normalised = Carbon::parse($raw)->format('Y-m-d');
                } catch (\Throwable) {
                    $normalised = null;
                }
            }

            if ($normalised !== $raw) {
                DB::table('follow_up_children')
                    ->where('id', $row->id)
                    ->update(['discharge_date' => $normalised]);
            }
        }

        Schema::table('follow_up_children', function (Blueprint $table): void {
            $table->date('discharge_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_children', function (Blueprint $table): void {
            $table->string('discharge_date')->nullable()->change();
        });
    }
};
