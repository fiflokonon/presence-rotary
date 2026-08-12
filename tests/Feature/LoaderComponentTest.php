<?php

use App\Models\ClubSetting;

it('renders the tenant logo without rotation by default', function () {
    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('ife-logo.png')
        ->toContain('object-contain')
        ->toContain('h-8 w-8')
        ->not->toContain('animate-spin');
});

it('lets callers override the logo sizing classes', function () {
    $html = (string) $this->blade('<x-loader class="h-16 w-16" />');

    expect($html)
        ->toContain('h-16 w-16')
        ->not->toContain('h-8 w-8')
        ->not->toContain('animate-spin');
});

it('renders three bouncing dots in the club primary color', function () {
    $html = (string) $this->blade('<x-loader />');

    expect(substr_count($html, 'animate-bounce'))->toBe(3);
    expect($html)->toContain('#0B73C5');
});

it('uses the club logo and primary color when configured', function () {
    ClubSetting::query()->first()->update([
        'logo_path' => 'tenants/1/club/logo.png',
        'primary_color' => '#FF8800',
    ]);

    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('tenants/1/club/logo.png')
        ->toContain('#FF8800')
        ->not->toContain('ife-logo.png');
});
