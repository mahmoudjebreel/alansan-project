<?php

namespace Database\Factories;

use App\Models\IndividualCounseling;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal valid row. Only the columns the schema requires are filled; the
 * permission tests care about who may touch a row, not what is in it.
 *
 * @extends Factory<IndividualCounseling>
 */
class IndividualCounselingFactory extends Factory
{
    protected $model = IndividualCounseling::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => '2026-01-15',
            'child_name' => 'طفل تجريبي',
            'mother_id_number' => (string) fake()->unique()->numberBetween(400000000, 499999999),
            'mother_name' => 'أم تجريبية',
        ];
    }
}
