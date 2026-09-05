<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a Group Session be held at the Al Helou shelter.
 *
 * The shelter is not new to the system: Individual Counseling has offered it
 * since that module was written, and it has its own translations. Group
 * Sessions simply never got it, so a session held there could not be recorded
 * at all - not by hand, and not by upload, where a hundred and fifty two rows
 * of one camp's work were refused as an invalid shelter name.
 *
 * Only the accepted value list changes; no row is touched.
 */
return new class extends Migration
{
    private const VALUES = ['mosaab_camp', 'mahabba', 'el_salam', 'el_qoqa', 'al_helou'];

    private const PREVIOUS_VALUES = ['mosaab_camp', 'mahabba', 'el_salam', 'el_qoqa'];

    public function up(): void
    {
        $this->retype(self::VALUES);
    }

    public function down(): void
    {
        // Sessions recorded at Al Helou cannot survive the narrower list.
        DB::table('group_sessions')
            ->where('shelter_name', 'al_helou')
            ->update(['shelter_name' => 'el_qoqa']);

        $this->retype(self::PREVIOUS_VALUES);
    }

    /**
     * @param  array<string>  $values
     */
    private function retype(array $values): void
    {
        Schema::table('group_sessions', function (Blueprint $table) use ($values): void {
            $table->enum('shelter_name', $values)->change();
        });
    }
};
