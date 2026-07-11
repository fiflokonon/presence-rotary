<?php

use App\Models\MeetingSession;
use App\Models\User;

it('shows the sidebar navigation and logo to an authenticated admin', function () {
    MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('ife-logo.png', false)
        ->assertSee('aria-label="Ouvrir le menu"', false)
        ->assertSee('href="'.route('admin.sessions.index').'"', false);
});
