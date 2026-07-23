<?php

use App\Models\ClubSetting;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.club-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.club-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows the form pre-filled with the seeded club identity', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.club-settings.edit'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife')
        ->assertSee('District 9103');
});

it('updates the club settings row', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => 'RC Nouveau Nom',
            'tagline' => 'District 9999',
            'primary_color' => '#123456',
            'secondary_color' => '#abcdef',
            'address' => '10 rue du Club',
            'phone' => '+229 00 00 00 00',
            'email' => 'contact@club.test',
            'website' => 'https://club.test',
            'facebook_url' => 'https://facebook.com/club',
            'instagram_url' => 'https://instagram.com/club',
        ])->assertRedirect(route('admin.club-settings.edit'));

    $clubSetting = ClubSetting::current();

    expect($clubSetting->name)->toBe('RC Nouveau Nom')
        ->and($clubSetting->tagline)->toBe('District 9999')
        ->and($clubSetting->primary_color)->toBe('#123456')
        ->and($clubSetting->address)->toBe('10 rue du Club')
        ->and($clubSetting->website)->toBe('https://club.test');
});

it('rejects an invalid payload', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => '',
            'primary_color' => 'not-a-color',
            'secondary_color' => 'not-a-color',
            'email' => 'not-an-email',
            'website' => 'not-a-url',
        ])->assertSessionHasErrors(['name', 'primary_color', 'secondary_color', 'email', 'website']);
});

it('uploads and stores a new logo, replacing the previous file', function () {
    Storage::fake('public');

    $tenantId = app(TenantContext::class)->current()->id;
    $oldPath = "tenants/{$tenantId}/club/old-logo.png";

    $clubSetting = ClubSetting::current();
    $clubSetting->update(['logo_path' => $oldPath]);
    Storage::disk('public')->put($oldPath, 'fake-image-content');

    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => $clubSetting->name,
            'primary_color' => $clubSetting->primary_color,
            'secondary_color' => $clubSetting->secondary_color,
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect(route('admin.club-settings.edit'));

    Storage::disk('public')->assertMissing($oldPath);

    $newPath = ClubSetting::current()->logo_path;

    expect($newPath)->not->toBeNull()
        ->and($newPath)->toStartWith("tenants/{$tenantId}/club/");
    Storage::disk('public')->assertExists($newPath);
});
