<?php

use App\Models\ClubSetting;

it('seeds a single club setting row with the current branding defaults', function () {
    $clubSetting = ClubSetting::current();

    expect($clubSetting)->not->toBeNull()
        ->and($clubSetting->name)->toBe('RC Cotonou Ife')
        ->and($clubSetting->tagline)->toBe('District 9103')
        ->and($clubSetting->logo_path)->toBeNull()
        ->and($clubSetting->logoUrl())->toContain('ife-logo.png')
        ->and($clubSetting->primary_color)->toBe('#0B73C5')
        ->and($clubSetting->secondary_color)->toBe('#17A8E5')
        ->and($clubSetting->hasContactInfo())->toBeFalse()
        ->and($clubSetting->hasSocialLinks())->toBeFalse();
});
