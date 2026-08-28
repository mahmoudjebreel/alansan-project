<?php

namespace Database\Factories;

use App\Models\Child;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Child>
 */
class ChildFactory extends Factory
{
    protected $model = Child::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_type' => 'new',
            'name' => fake()->name(),
            'child_id' => 'CH-' . fake()->unique()->numerify('######'),
            'organization' => fake()->company(),
            'implementing_partner' => fake()->company(),
            'date_of_reporting' => fake()->date(),
            'sex' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-5 years', '-2 months'),
            'governorate' => fake()->city(),
        ];
    }
}
