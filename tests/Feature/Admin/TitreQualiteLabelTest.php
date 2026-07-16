<?php

use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use App\Models\User;

it('shows Titre/Qualité instead of Poste on the public check-in form', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    // A plain GET to attendance.show never renders <x-attendance-form> ($email is
    // null until a lookup/store round-trip sets it) — post to attendance.lookup,
    // like the analogous Task 1 test (OrganisationLabelTest), to actually reach it.
    $this->post(route('attendance.lookup'), ['email' => 'jean.dupont@example.com'])
        ->assertOk()
        ->assertSee('Titre/Qualité*', false);
});

it('shows Titres/Qualités instead of Postes in the admin sidebar and pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('Titres/Qualités')
        ->assertSee('Ajouter un titre/qualité');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.create'))
        ->assertOk()
        ->assertSee('Ajouter un titre/qualité')
        ->assertSee('Créer le titre/qualité');
});

it('shows Titres/Qualités liés on the organisation admin forms', function () {
    $title = Title::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.create'))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.edit', $title))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');
});

it('shows Titre/Qualité instead of Poste in the member edit label', function () {
    $member = Member::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('>Titre/Qualité<', false);
});

it('uses Titre/Qualité wording in the position-required validation message', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id' => 'Le titre/qualité est obligatoire pour cette organisation.']);
});

it('uses Titre/Qualité wording in the position-mismatch validation message', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $jci = Title::where('name', 'JCI')->sole();
    $rotaryOnlyPosition = Title::where('name', 'Rotary')->sole()->positions()->where('name', 'PDG')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $jci->id,
        'position_id' => $rotaryOnlyPosition->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id' => 'Le titre/qualité sélectionné ne correspond pas à l\'organisation choisie.']);
});

it('shows a friendly message using Titre/Qualité wording when deleting a referenced position', function () {
    $position = Position::factory()->create();
    Member::factory()->create(['position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'))
        ->assertSessionHas('error', 'Ce titre/qualité est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
});
