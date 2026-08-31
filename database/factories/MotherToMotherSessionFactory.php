<?php

namespace Database\Factories;

use App\Models\MotherToMotherSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal valid row. Only the columns the schema requires are filled; the
 * permission tests care about who may touch a row, not what is in it.
 *
 * @extends Factory<MotherToMotherSession>
 */
class MotherToMotherSessionFactory extends Factory
{
    protected $model = MotherToMotherSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_date' => '2026-01-15',
            'session_group_number' => '1',
            'session_subject' => 'bf_support',
            'locality' => 'mosaab_camp',
            'shelter_name' => 'مركز مصعب',
            'id_number' => (string) fake()->unique()->numberBetween(400000000, 499999999),
            'full_name_ar' => 'أم أحمد',
            'visit_type' => 'new',
            'category' => 'pregnant',
            'is_pwd' => false,
            'marital_status' => 'married',
            'phone_number' => '0599123456',
        ];
    }
}
