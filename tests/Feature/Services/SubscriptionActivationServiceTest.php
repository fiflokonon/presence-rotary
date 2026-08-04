<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use App\Services\SubscriptionActivationService;

it('activates a completed payment into a new subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['duration_months' => 3, 'price' => 15000]);
    $transaction = Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-ACTIVATE',
        'amount' => 15000,
        'status' => Transaction::STATUS_PENDING,
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'amount' => 15000,
            'custom_data' => ['reference' => 'SUB-ACTIVATE'],
        ]);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => true, 'status' => 'completed', 'message' => 'Abonnement activé avec succès']);

    $transaction->refresh();
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);

    $subscription = $tenant->currentSubscription();
    expect($subscription->source)->toBe(Subscription::SOURCE_PAID)
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->transaction_id)->toBe($transaction->id)
        ->and($subscription->start_date->diffInDays($subscription->end_date, absolute: true))->toBeGreaterThanOrEqual(89);
});

it('is idempotent when called twice for the same reference (poll + webhook race)', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-RACE',
        'status' => Transaction::STATUS_PENDING,
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->twice()->andReturn([
            'success' => true,
            'status' => 'completed',
            'amount' => 5000,
            'custom_data' => ['reference' => 'SUB-RACE'],
        ]);
    });

    $service = app(SubscriptionActivationService::class);
    $service->activateFromToken('token-a');
    $result = $service->activateFromToken('token-b');

    expect($result)->toBe(['success' => true, 'status' => 'completed', 'message' => 'Abonnement déjà activé']);
    expect(Subscription::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('reports pending without creating a subscription', function () {
    $tenant = Tenant::factory()->create();
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'reference' => 'SUB-PENDING']);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'custom_data' => ['reference' => 'SUB-PENDING'],
        ]);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => true, 'status' => 'pending', 'message' => 'Paiement en attente de confirmation...']);
    expect(Subscription::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('reports failed when the gateway call itself fails', function () {
    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn(['success' => false, 'message' => 'boom']);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => false, 'status' => 'pending', 'message' => 'Vérification en cours...']);
});

it('stacks the new period onto the current subscription end date when renewing early', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['duration_months' => 1]);
    $currentEnd = now()->addDays(10);
    Subscription::factory()->create(['tenant_id' => $tenant->id, 'end_date' => $currentEnd]);
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-STACK',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-STACK'],
        ]);
    });

    app(SubscriptionActivationService::class)->activateFromToken('some-token');

    $newSubscription = Subscription::where('tenant_id', $tenant->id)->orderByDesc('id')->first();
    expect($newSubscription->start_date->isSameDay($currentEnd))->toBeTrue();
});
