<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->get(route('admin.sessions.show', $meetingSession))
        ->assertRedirect(route('admin.login'));
});

it('shows counters and the roster to an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'name' => 'Jean Dupont',
        'present' => true,
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Invité')->sole()->id,
        'name' => 'Awa Bello',
        'present' => false,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('Awa Bello')
        ->assertSee('1/2');
});

it('toggles an attendance present flag', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create(['present' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.sessions.show', $meetingSession));

    expect($attendance->fresh()->present)->toBeFalse();
});

it('requires authentication to toggle presence', function () {
    $attendance = Attendance::factory()->create();

    $this->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.login'));

    expect($attendance->fresh()->present)->toBeTrue();
});

it('exposes a QR code panel for sharing the public form link', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('QR code')
        ->assertSee('qrCodePanel(', false);
});

it('exposes a title/qualité filter listing the titles present in the roster', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'name' => 'Jean Dupont',
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Invité')->sole()->id,
        'name' => 'Awa Bello',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('x-model="activeTitle"', false)
        ->assertSee('Toutes les organisations')
        ->assertSee('Rotary')
        ->assertSee('Invité');
});

it('shows the poste/qualité alongside the titre when set', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotaryTitle = Title::where('name', 'Rotary')->sole();
    $president = $rotaryTitle->positions()->where('name', 'Président')->sole();

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee($president->name);
});

it('does not fragment the titre filter by poste', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotaryTitle = Title::where('name', 'Rotary')->sole();
    $president = $rotaryTitle->positions()->where('name', 'Président')->sole();
    $member = $rotaryTitle->positions()->where('name', 'Membre')->sole();

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $member->id,
        'name' => 'Awa Bello',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\), JSON\.parse\('.+?'\)\)/s", $response->getContent(), $matches);
    $json = str_replace(chr(92).'u0022', '"', $matches[1]);
    $records = json_decode($json, true);

    expect(collect($records)->pluck('title')->unique()->values()->all())->toBe(['Rotary']);
});

it('shows a link back to the sessions list', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Retour aux séances')
        ->assertSee('href="'.route('admin.sessions.index').'"', false);
});

it('includes each attendances position order in the roster payload', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotaryTitle = Title::where('name', 'Rotary')->sole();
    $president = $rotaryTitle->positions()->where('name', 'Président')->sole();
    $member = $rotaryTitle->positions()->where('name', 'Membre')->sole();
    $president->update(['order' => 0]);
    $member->update(['order' => 10]);

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $member->id,
        'name' => 'Awa Bello',
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\), JSON\.parse\('.+?'\)\)/s", $response->getContent(), $matches);
    $json = str_replace(chr(92).'u0022', '"', $matches[1]);
    $records = collect(json_decode($json, true))->keyBy('name');

    expect($records['Jean Dupont']['positionOrder'])->toBe(0)
        ->and($records['Awa Bello']['positionOrder'])->toBe(10);
});

it('exposes a sort-mode toggle button on the roster', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('sortMode = sortMode', false)
        ->assertSee('x-show="sortMode === \'position\'"', false);
});

it('shows one stat tile per principal organisation plus an Autres organisations tile', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id]);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Rotary')
        ->assertSee('Rotaract')
        ->assertSee(Title::OTHER_ORGANIZATIONS_LABEL);
});

it('exposes group quick-filter buttons instead of the old fixed category buttons', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('activeGroup = ', false)
        ->assertDontSee('Bureau / Officiels')
        ->assertDontSee('Rotaractiens');
});

it('includes each attendances group label in the roster payload', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id, 'name' => 'Awa Bello']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\), JSON\.parse\('(.+?)'\)\)/s", $response->getContent(), $matches);
    $records = collect(json_decode(str_replace(chr(92).'u0022', '"', $matches[1]), true))->keyBy('name');

    expect($records['Jean Dupont']['groupLabel'])->toBe('Rotary')
        ->and($records['Awa Bello']['groupLabel'])->toBe(Title::OTHER_ORGANIZATIONS_LABEL);
});

it('orders roster groups with principal organisations first and Autres organisations last', function () {
    $meetingSession = MeetingSession::factory()->create();
    $expectedPrincipalOrder = Title::principal()->orderBy('order')->orderBy('name')->pluck('name')->all();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    // No attendances are seeded, so the first constructor argument renders as a
    // literal `[]` rather than `JSON.parse('[]')` (see Illuminate\Support\Js::
    // convertJsonToJavaScriptExpression, which special-cases empty arrays/objects).
    // Only the group-order argument matters here, so match its tail directly.
    preg_match("/, JSON\.parse\('(.+?)'\)\)/s", $response->getContent(), $matches);
    $groupLabels = json_decode(str_replace(chr(92).'u0022', '"', $matches[1]), true);

    expect($groupLabels)->toBe([...$expectedPrincipalOrder, Title::OTHER_ORGANIZATIONS_LABEL]);
});
