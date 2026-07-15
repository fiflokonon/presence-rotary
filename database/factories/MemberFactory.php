<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_id' => Title::factory(),
            'position_id' => null,
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
