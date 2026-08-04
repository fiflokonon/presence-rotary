<?php

use App\Models\CheckinSetting;
use App\Models\User;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.checkin-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.checkin-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows the guest option checked by default when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.checkin-settings.edit'))
        ->assertOk()
        ->assertSee('checked', false);
});

it('creates the checkin settings row disabled when the checkbox is left unchecked', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), [])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_guest_option)->toBeFalse();
});

it('enables the guest option when the checkbox is submitted checked', function () {
    CheckinSetting::create(['show_guest_option' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), ['show_guest_option' => '1'])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_guest_option)->toBeTrue();
});

it('shows the club field toggle unchecked by default when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.checkin-settings.edit'))
        ->assertOk()
        ->assertSee('Afficher le champ « Nom de club » pour les invités');
});

it('creates the checkin settings row with the club field disabled when the checkbox is left unchecked', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), [])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_club_field_for_guests)->toBeFalse();
});

it('enables the club field for guests when the checkbox is submitted checked', function () {
    CheckinSetting::create(['show_club_field_for_guests' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), ['show_club_field_for_guests' => '1'])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_club_field_for_guests)->toBeTrue();
});
