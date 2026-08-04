<?php

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;
use App\Services\TenantContext;

it('activates the subscription and returns completed when the gateway confirms', function () {
    $tenant = app(TenantContext::class)->current();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-POLL',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-POLL'],
        ]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.subscription.status', ['token' => 'tok-poll']))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'completed']);

    expect($tenant->currentSubscription())->not->toBeNull();
});

it('returns pending while the gateway has not confirmed yet', function () {
    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn(['success' => false]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.subscription.status', ['token' => 'tok-poll-2']))
        ->assertOk()
        ->assertJson(['success' => false, 'status' => 'pending']);
});
