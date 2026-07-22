<?php

use App\Models\ClubSetting;

it('renders the configured club name, tagline and colors on the check-in page', function () {
    ClubSetting::current()->update([
        'name' => 'Club Test',
        'tagline' => 'Zone 42',
        'primary_color' => '#123456',
        'secondary_color' => '#abcdef',
    ]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Club Test')
        ->assertSee('Zone 42')
        ->assertSee('#123456', false)
        ->assertSee('#abcdef', false);
});
