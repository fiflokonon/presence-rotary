<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Models\User;

it('requires authentication to export the PDF', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->get(route('admin.sessions.export-pdf', $meetingSession))
        ->assertRedirect(route('admin.login'));
});

it('downloads a PDF grouped by category for an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.export-pdf', $meetingSession));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});
