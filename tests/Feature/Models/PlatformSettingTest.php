<?php

use App\Models\PlatformSetting;

it('has a seeded default grace period of 7 days', function () {
    expect(PlatformSetting::current()->default_grace_period_days)->toBe(7);
});
