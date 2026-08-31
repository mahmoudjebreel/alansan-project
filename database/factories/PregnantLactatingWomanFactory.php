<?php

namespace Database\Factories;

use App\Models\PregnantLactatingWoman;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PregnantLactatingWoman>
 */
class PregnantLactatingWomanFactory extends Factory
{
    protected $model = PregnantLactatingWoman::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_type' => 'new',
            'full_name_ar' => fake()->name(),
            'mother_id' => 'PLW-' . fake()->unique()->numerify('######'),
            'organization' => fake()->company(),
            'implementing_partner' => fake()->company(),
            'date_of_reporting' => fake()->date(),
            'date_of_birth' => fake()->dateTimeBetween('-40 years', '-18 years'),
            'status_type' => fake()->randomElement(['pregnant', 'lactating']),
            'governorate' => fake()->city(),
        ];
    }
}
