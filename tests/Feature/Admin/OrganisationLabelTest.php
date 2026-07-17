<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use App\Models\User;

it('shows Organisation instead of Titre on the public check-in form', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'jean.dupont@example.com'])
        ->assertOk()
        ->assertSee('Organisation*', false)
        ->assertDontSee('Titre*', false);
});

it('shows Organisation instead of Titre in the admin sidebar', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Organisations')
        ->assertDontSee('>Titres<', false);
});

it('shows Organisation instead of Titre on the titles admin pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Ajouter une organisation');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.create'))
        ->assertOk()
        ->assertSee('Ajouter une organisation')
        ->assertSee('Créer l\'organisation', false);
});

it('shows Organisation instead of Titre on the session PDF export header', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
    ]);

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => ['Rotary', 'Rotaract', Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('<th>Organisation</th>')
        ->and($html)->not->toContain('<th>Titre</th>');
});

it('shows Organisation instead of Titre in the member detail/edit labels', function () {
    $member = Member::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.show', $member))
        ->assertOk()
        ->assertSee('Organisation / Titre-Qualité');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('>Organisation<', false);
});

it('shows a friendly message using Organisation wording when deleting a referenced title', function () {
    $title = Title::factory()->create();
    Member::factory()->create(['title_id' => $title->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'))
        ->assertSessionHas('error', 'Cette organisation est utilisée par des membres ou des présences existantes — désactivez-la plutôt que de la supprimer.');
});
