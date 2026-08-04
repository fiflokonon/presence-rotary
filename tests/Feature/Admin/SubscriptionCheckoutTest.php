<?php

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;

it('creates a pending transaction and redirects to the pending page on success', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => true, 'token' => 'tok-123']);
    });

    $response = $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [
            'plan_id' => $plan->id,
            'payment_method' => 'mtn_momo',
            'phone' => '90000000',
        ]);

    $response->assertRedirect(route('admin.subscription.pending', ['token' => 'tok-123']));

    $transaction = Transaction::where('plan_id', $plan->id)->firstOrFail();
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING)
        ->and($transaction->amount)->toBe($plan->price)
        ->and($transaction->payment_method)->toBe('mtn_momo');
});

it('shows an error and does not create a transaction when the gateway fails to initiate', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => false, 'message' => 'Configuration PayPlus manquante']);
    });

    $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [
            'plan_id' => $plan->id,
            'payment_method' => 'mtn_momo',
            'phone' => '90000000',
        ])->assertRedirect(route('admin.subscription.index'))
        ->assertSessionHas('error', 'Configuration PayPlus manquante');

    expect(Transaction::count())->toBe(0);
});

it('validates the checkout form', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [])
        ->assertSessionHasErrors(['plan_id', 'payment_method', 'phone']);
});

it('shows the pending page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.pending', ['token' => 'tok-123']))
        ->assertOk()
        ->assertSee('tok-123');
});
