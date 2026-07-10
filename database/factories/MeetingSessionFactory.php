<?php

namespace Database\Factories;

use App\Models\MeetingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingSession>
 */
class MeetingSessionFactory extends Factory
{
    protected $model = MeetingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Réunion du '.fake()->dayOfWeek(),
            'date' => fake()->date(),
            'time' => '12:30:00',
            'is_open' => true,
            'is_active' => false,
        ];
    }
}
