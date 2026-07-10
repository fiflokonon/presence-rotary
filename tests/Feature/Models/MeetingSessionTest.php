<?php

use App\Models\MeetingSession;
use Illuminate\Support\Carbon;

it('activates a session and deactivates any other active session', function () {
    $first = MeetingSession::factory()->create(['is_active' => true]);
    $second = MeetingSession::factory()->create(['is_active' => false]);

    $second->activate();

    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue();
});

it('resolves the active session', function () {
    MeetingSession::factory()->create(['is_active' => false]);
    $active = MeetingSession::factory()->create(['is_active' => true]);

    expect(MeetingSession::active()->id)->toBe($active->id);
});

it('returns null when no session is active', function () {
    MeetingSession::factory()->create(['is_active' => false]);

    expect(MeetingSession::active())->toBeNull();
});

it('casts date, is_open, and is_active', function () {
    $meetingSession = MeetingSession::factory()->create([
        'date' => '2026-07-10',
        'is_open' => 1,
        'is_active' => 0,
    ]);

    expect($meetingSession->date)->toBeInstanceOf(Carbon::class)
        ->and($meetingSession->is_open)->toBeTrue()
        ->and($meetingSession->is_active)->toBeFalse();
});
