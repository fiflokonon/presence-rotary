<?php

use App\Enums\AttendanceCategory;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;

it('derives its category from its title', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Rotaractors]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->category)->toBe(AttendanceCategory::Rotaractors);
});

it('belongs to a title and an optional position', function () {
    $title = Title::factory()->create();
    $attendance = Attendance::factory()->create(['title_id' => $title->id, 'position_id' => null]);

    expect($attendance->title->is($title))->toBeTrue()
        ->and($attendance->position)->toBeNull();
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

it('derives its group label from a principal titles own name', function () {
    $title = Title::factory()->create(['is_principal' => true]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->groupLabel)->toBe($title->name);
});

it('derives its group label as Autres organisations for a non-principal title', function () {
    $title = Title::factory()->create(['is_principal' => false]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->groupLabel)->toBe(Title::OTHER_ORGANIZATIONS_LABEL);
});
