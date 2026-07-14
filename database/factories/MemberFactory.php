<?php

namespace Database\Factories;

use App\Enums\AttendanceTitle;
use App\Models\Member;
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
            'title' => fake()->randomElement(AttendanceTitle::cases()),
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
