<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayPlusGateway;

it('activates the subscription on a successful callback', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-WEBHOOK',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-WEBHOOK'],
        ]);
    });

    $this->postJson('/payplus/callback', ['token' => 'tok-webhook', 'response_code' => '00'])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    expect($tenant->currentSubscription())->not->toBeNull();
});

it('rejects a callback with a non-00 response code without calling the gateway', function () {
    $this->postJson('/payplus/callback', ['token' => 'tok-bad', 'response_code' => '99'])
        ->assertStatus(400)
        ->assertJson(['status' => 'error']);
});

it('rejects a callback without a token', function () {
    $this->postJson('/payplus/callback', ['response_code' => '00'])
        ->assertStatus(400);
});
