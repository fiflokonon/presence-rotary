<?php

namespace Database\Factories;

use App\Enums\AttendanceCategory;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Title>
 */
class TitleFactory extends Factory
{
    protected $model = Title::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'category' => fake()->randomElement(AttendanceCategory::cases()),
            'is_active' => true,
        ];
    }
}
