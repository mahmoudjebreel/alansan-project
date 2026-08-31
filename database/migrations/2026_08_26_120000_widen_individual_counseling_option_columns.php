<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen three Individual Counseling option columns so the Create/Edit form can
 * offer the full option lists the programme actually uses:
 *
 *   p_l           pregnant / lactating                    ->  L / P / P+L
 *   consultation  (no relactation option)                 ->  + relactation
 *   pregnancy     P only (could not express "No")         ->  yes / no
 *
 * Each column is first widened to accept both the old and the new values so
 * existing rows can be translated, then narrowed to the final list.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->retype('p_l', ['pregnant', 'lactating', 'L', 'P', 'P+L']);
        DB::table('individual_counselings')->where('p_l', 'pregnant')->update(['p_l' => 'P']);
        DB::table('individual_counselings')->where('p_l', 'lactating')->update(['p_l' => 'L']);
        $this->retype('p_l', ['L', 'P', 'P+L']);

        $this->retype('consultation', ['complementary_feeding', 'bf_support', 'relactation', 'other']);

        $this->retype('pregnancy', ['P', 'yes', 'no']);
        DB::table('individual_counselings')->where('pregnancy', 'P')->update(['pregnancy' => 'yes']);
        $this->retype('pregnancy', ['yes', 'no']);
    }

    public function down(): void
    {
        $this->retype('pregnancy', ['P', 'yes', 'no']);
        DB::table('individual_counselings')->where('pregnancy', 'yes')->update(['pregnancy' => 'P']);
        DB::table('individual_counselings')->where('pregnancy', 'no')->update(['pregnancy' => null]);
        $this->retype('pregnancy', ['P']);

        DB::table('individual_counselings')->where('consultation', 'relactation')->update(['consultation' => 'other']);
        $this->retype('consultation', ['complementary_feeding', 'bf_support', 'other']);

        $this->retype('p_l', ['pregnant', 'lactating', 'L', 'P', 'P+L']);
        DB::table('individual_counselings')->where('p_l', 'P')->update(['p_l' => 'pregnant']);
        DB::table('individual_counselings')->whereIn('p_l', ['L', 'P+L'])->update(['p_l' => 'lactating']);
        $this->retype('p_l', ['pregnant', 'lactating']);
    }

    /**
     * Replace the accepted value list of one nullable enum column.
     *
     * @param  array<string>  $values
     */
    private function retype(string $column, array $values): void
    {
        Schema::table('individual_counselings', function (Blueprint $table) use ($column, $values): void {
            $table->enum($column, $values)->nullable()->change();
        });
    }
};
