<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;

it('backfills members from existing attendance emails, using the most recent row per email', function () {
    $migrationPath = glob(database_path('migrations/*_backfill_members_from_attendances.php'))[0];
    $migration = include $migrationPath;

    $olderSession = MeetingSession::factory()->create(['date' => '2026-01-10']);
    $recentSession = MeetingSession::factory()->create(['date' => '2026-02-10']);

    $olderAttendance = Attendance::factory()->create([
        'meeting_session_id' => $olderSession->id,
        'email' => 'jean@example.com',
        'classification' => 'Ancienne classification',
    ]);

    $recentAttendance = Attendance::factory()->create([
        'meeting_session_id' => $recentSession->id,
        'email' => 'JEAN@example.com',
        'classification' => 'Classification actuelle',
    ]);

    $blankEmailAttendance = Attendance::factory()->create(['email' => null]);

    $migration->up();

    $member = Member::where('email', 'jean@example.com')->sole();

    expect($member->classification)->toBe('Classification actuelle')
        ->and($olderAttendance->fresh()->member_id)->toBe($member->id)
        ->and($recentAttendance->fresh()->member_id)->toBe($member->id)
        ->and($blankEmailAttendance->fresh()->member_id)->toBeNull()
        ->and(Member::count())->toBe(1);
});
