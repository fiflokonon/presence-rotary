<?php

use App\Models\ClubSetting;
use App\Models\User;

it('renders the configured club name and brand colors in the admin layout', function () {
    ClubSetting::current()->update([
        'name' => 'Club Admin Test',
        'primary_color' => '#111111',
        'secondary_color' => '#222222',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Club Admin Test')
        ->assertSee('#111111', false)
        ->assertSee('#222222', false);
});
