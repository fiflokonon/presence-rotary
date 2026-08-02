<?php

use App\Models\User;

it('requires authentication to download the import template', function () {
    $this->get(route('admin.members.import-template'))->assertRedirect(route('admin.login'));
});

it('downloads an xlsx template with the expected headings', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.members.import-template'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
});
