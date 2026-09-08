<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give two exits their own Follow Up Child discharge outcome.
 *
 * "Non Responded" and "Referred For Medical Reason (inpt)" were both folded
 * into 'discharge_to_other' on import because the option list had no name for
 * them, so a sheet that distinguished the two came out of the importer as one
 * outcome. They are separate outcomes from here on.
 *
 * The column is an enum, so the two names have to be added to it before a row
 * can carry them. Every existing value stays in the list - 'discharge_to_other'
 * included - so no stored outcome becomes invalid and no row is touched: this
 * migration widens what the column accepts and writes nothing.
 *
 * Rows already imported as 'discharge_to_other' are deliberately left alone.
 * The importer collapsed the three spellings into one value without recording
 * which it read, so there is nothing in the row to tell a "Non Responded" from
 * a "Referred For Medical Reason" from the catch-all the teams type, and any
 * back-fill would be a guess.
 */
return new class extends Migration
{
    /**
     * @var array<string>
     */
    private const OUTCOMES_BEFORE = [
        'cured', 'defaulted', 'discharge_to_opt', 'discharge_to_other',
        'died', 'under_follow_up',
    ];

    /**
     * @var array<string>
     */
    private const OUTCOMES_AFTER = [
        'cured', 'defaulted', 'discharge_to_opt', 'discharge_to_other',
        'non_responded', 'referred_medical_inpt', 'died', 'under_follow_up',
    ];

    public function up(): void
    {
        $this->setOutcomesTo(self::OUTCOMES_AFTER);
    }

    /**
     * Narrow the column back. Any row that has since been discharged under one
     * of the two new outcomes would no longer be a legal value, so those rows
     * are returned to the outcome they used to be imported as rather than left
     * to be rejected or silently emptied by the column change.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('follow_up_children')
            ->whereIn('discharge_outcome', ['non_responded', 'referred_medical_inpt'])
            ->update(['discharge_outcome' => 'discharge_to_other']);

        $this->setOutcomesTo(self::OUTCOMES_BEFORE);
    }

    /**
     * @param  array<string>  $outcomes
     */
    private function setOutcomesTo(array $outcomes): void
    {
        Schema::table('follow_up_children', function (Blueprint $table) use ($outcomes): void {
            $table->enum('discharge_outcome', $outcomes)->nullable()->change();
        });
    }
};
