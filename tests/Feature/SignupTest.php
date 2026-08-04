<?php

use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;

it('shows the signup form with active plans', function () {
    Plan::factory()->create(['name' => 'Plan Signup', 'is_active' => true]);

    $this->get(superAdminUrl('inscription'))
        ->assertOk()
        ->assertSee('Plan Signup');
});

it('creates a tenant-less pending transaction and redirects to the pending page on success', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => true, 'token' => 'tok-signup']);
    });

    $response = $this->post(superAdminUrl('inscription'), [
        'club_name' => 'Rotary Club Signup',
        'admin_name' => 'Admin Signup',
        'admin_email' => 'admin@signup.test',
        'plan_id' => $plan->id,
        'payment_method' => 'mtn_momo',
        'phone' => '90000000',
    ]);

    $response->assertRedirect();

    $transaction = Transaction::whereNull('tenant_id')->firstOrFail();
    expect($transaction->metadata['club_name'])->toBe('Rotary Club Signup')
        ->and($transaction->metadata['admin_name'])->toBe('Admin Signup')
        ->and($transaction->metadata['admin_email'])->toBe('admin@signup.test')
        ->and($transaction->plan_id)->toBe($plan->id);
});

it('validates the signup form', function () {
    $this->post(superAdminUrl('inscription'), [])
        ->assertSessionHasErrors(['club_name', 'admin_name', 'admin_email', 'plan_id', 'payment_method', 'phone']);
});
