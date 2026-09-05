<?php

namespace Database\Factories;

use App\Models\GroupSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal valid row. Only the columns the schema requires are filled; the
 * permission tests care about who may touch a row, not what is in it.
 *
 * @extends Factory<GroupSession>
 */
class GroupSessionFactory extends Factory
{
    protected $model = GroupSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_date' => '2026-01-15',
            'session_group_number' => '1',
            'session_subject' => 'bf_support',
            'locality' => 'tal_al_hawa',
            'shelter_name' => 'mosaab_camp',
            'id_number' => (string) fake()->unique()->numberBetween(400000000, 499999999),
            'full_name_ar' => 'أم محمد',
            'visit_type' => 'new',
            'category' => 'pregnant',
            'is_pwd' => false,
            'marital_status' => 'married',
            'phone_number' => '0599123456',
            'has_gsfsh' => false,
            // The commodity handed out, or nothing at all - not a yes/no.
            'receives_supplementary' => null,
        ];
    }
}
