<?php

use App\Models\CheckinSetting;

it('defaults guestOptionEnabled to true when no row exists', function () {
    expect(CheckinSetting::current())->toBeNull()
        ->and(CheckinSetting::guestOptionEnabled())->toBeTrue();
});

it('reflects the stored value once a row exists', function () {
    CheckinSetting::create(['show_guest_option' => false]);

    expect(CheckinSetting::guestOptionEnabled())->toBeFalse();
});
