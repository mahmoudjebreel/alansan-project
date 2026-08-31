<?php

namespace Database\Factories;

use App\Models\FollowUpChild;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal valid row. Only the columns the schema requires are filled; the
 * permission tests care about who may touch a row, not what is in it.
 *
 * @extends Factory<FollowUpChild>
 */
class FollowUpChildFactory extends Factory
{
    protected $model = FollowUpChild::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_number' => (string) fake()->unique()->numberBetween(400000000, 499999999),
            'child_name' => 'طفل متابعة',
            'sex' => 'M',
            'governorate' => 'Gaza',
        ];
    }
}
