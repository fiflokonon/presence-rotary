<?php

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;

it('derives its category from its title', function () {
    $attendance = Attendance::factory()->create([
        'title' => AttendanceTitle::Rotaractien,
    ]);

    expect($attendance->category)->toBe(AttendanceCategory::Rotaractors);
});

it('casts its title to the AttendanceTitle enum', function () {
    $attendance = Attendance::factory()->create(['title' => 'Rotarien']);

    expect($attendance->title)->toBe(AttendanceTitle::Rotarien);
});

it('belongs to a meeting session', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    expect($attendance->meetingSession->is($meetingSession))->toBeTrue();
});

it('casts present and is_late to booleans', function () {
    $attendance = Attendance::factory()->create(['present' => 1, 'is_late' => 0]);

    expect($attendance->present)->toBeTrue()
        ->and($attendance->is_late)->toBeFalse();
});
