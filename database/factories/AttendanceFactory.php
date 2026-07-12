<?php

namespace Database\Factories;

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_session_id' => MeetingSession::factory(),
            'title' => fake()->randomElement(AttendanceTitle::cases()),
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => null,
            'present' => true,
            'is_late' => false,
        ];
    }
}
