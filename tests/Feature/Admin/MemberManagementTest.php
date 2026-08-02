<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login for every member route', function () {
    $member = Member::factory()->create();

    $this->get(route('admin.members.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.show', $member))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.edit', $member))->assertRedirect(route('admin.login'));
    $this->put(route('admin.members.update', $member), [])->assertRedirect(route('admin.login'));
});

it('redirects guests away from the create and store member routes', function () {
    $this->get(route('admin.members.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.members.store'), [])->assertRedirect(route('admin.login'));
});

it('creates a member from the admin panel', function () {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Awa Bello',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 11 22 33',
            'email' => 'awa.bello@example.com',
            'is_club_member' => '1',
        ]);

    $member = Member::where('email', 'awa.bello@example.com')->sole();

    $response->assertRedirect(route('admin.members.show', $member));
    expect($member->name)->toBe('Awa Bello')
        ->and($member->is_club_member)->toBeTrue();
});

it('creating a member without checking the club member box leaves it false', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Jean Dupont',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 00 00 00',
            'email' => 'jean.new@example.com',
        ]);

    expect(Member::where('email', 'jean.new@example.com')->sole()->is_club_member)->toBeFalse();
});

it('rejects creating a member with an email that already exists', function () {
    Member::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Duplicate',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 00 00 00',
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);

    expect(Member::where('email', 'existing@example.com')->count())->toBe(1);
});

it('lists members to an authenticated admin', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('jean@example.com');
});

it('filters the member list by search term', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'club' => 'RC Cotonou Ife']);
    Member::factory()->create(['name' => 'Awa Bello', 'club' => 'RC Porto-Novo']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index', ['search' => 'Porto-Novo']))
        ->assertOk()
        ->assertSee('Awa Bello')
        ->assertDontSee('Jean Dupont');
});

it('shows a member detail page with their attendance history', function () {
    $rotaryTitle = Title::where('name', 'Rotary')->sole();
    $president = $rotaryTitle->positions()->where('name', 'Président')->sole();

    $member = Member::factory()->create([
        'name' => 'Jean Dupont',
        'title_id' => $rotaryTitle->id,
        'position_id' => $president->id,
    ]);
    $meetingSession = MeetingSession::factory()->create(['title' => 'Réunion du 10 janvier']);

    Attendance::factory()->create([
        'member_id' => $member->id,
        'meeting_session_id' => $meetingSession->id,
        'classification' => 'Classification A',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.show', $member))
        ->assertOk()
        ->assertSee('Réunion du 10 janvier')
        ->assertSee('Classification A')
        ->assertSee($president->name);
});

it('updates a member', function () {
    $member = Member::factory()->create(['club' => 'RC Cotonou Ife']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => 'RC Porto-Novo',
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
        ])->assertRedirect(route('admin.members.show', $member));

    expect($member->fresh()->club)->toBe('RC Porto-Novo');
});

it('rejects an email that collides with another member', function () {
    Member::factory()->create(['email' => 'existing@example.com']);
    $member = Member::factory()->create(['email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);
});

it('does not offer an inactive title for a member whose current title is different', function () {
    $member = Member::factory()->create();
    Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertDontSee('Titre Retraité');
});

it('flags an existing member as a club member from the edit form', function () {
    $member = Member::factory()->create(['is_club_member' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
            'is_club_member' => '1',
        ]);

    expect($member->fresh()->is_club_member)->toBeTrue();
});

it('unflags a club member from the edit form by omitting the checkbox', function () {
    $member = Member::factory()->create(['is_club_member' => true]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
        ]);

    expect($member->fresh()->is_club_member)->toBeFalse();
});

it('shows a club member badge on the member index', function () {
    Member::factory()->create(['name' => 'Awa Bello', 'is_club_member' => true]);
    Member::factory()->create(['name' => 'Jean Dupont', 'is_club_member' => false]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index'));

    $response->assertOk()->assertSee('Membre du club');
});

it('still offers a members own inactive title and position on their edit form', function () {
    $title = Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);
    $position = Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);
    $title->positions()->attach($position);
    $member = Member::factory()->create(['title_id' => $title->id, 'position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('Titre Retraité (inactif)');
});
