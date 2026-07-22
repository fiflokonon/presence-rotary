<?php

use App\Models\Attendance;
use App\Models\ClubSetting;
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

it('groups the PDF export by principal organisation and Autres organisations', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id, 'name' => 'Awa Bello']);

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => ['Rotary', 'Rotaract', Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('<h2>Rotary (1)</h2>')
        ->and($html)->toContain('<h2>'.Title::OTHER_ORGANIZATIONS_LABEL.' (1)</h2>')
        ->and($html)->not->toContain('<h2>Rotaract (')
        ->and(strpos($html, 'Jean Dupont'))->toBeLessThan(strpos($html, 'Awa Bello'));
});

it('shows the configured club name and tagline in the PDF subtitle', function () {
    ClubSetting::current()->update(['name' => 'Club PDF Test', 'tagline' => 'Zone PDF']);

    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('Club PDF Test, Zone PDF');
});

it('includes the club contact info in the PDF footer when configured', function () {
    ClubSetting::current()->update([
        'address' => '12 avenue du Club',
        'phone' => '+229 22 22 22 22',
        'website' => 'https://club.test',
    ]);

    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('12 avenue du Club')
        ->and($html)->toContain('+229 22 22 22 22')
        ->and($html)->toContain('https://club.test');
});

it('omits the footer block when no contact info is configured', function () {
    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->not->toContain('class="footer"');
});
