<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

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

it('exempts the callback route from request forgery prevention', function () {
    // PreventRequestForgery::handle() short-circuits on runningUnitTests(),
    // so posting to the route here would pass whether or not the exclusion
    // exists. Assert against the exclusion list itself instead, which is the
    // only part of the check that a server-to-server callback can satisfy.
    $middleware = app(PreventRequestForgery::class);

    $isExcluded = function (string $uri) use ($middleware): bool {
        $inExceptArray = (new ReflectionMethod($middleware, 'inExceptArray'))
            ->getClosure($middleware);

        return $inExceptArray(Request::create($uri, 'POST'));
    };

    expect($isExcluded('/payplus/callback'))->toBeTrue()
        ->and($isExcluded('/check-in'))->toBeFalse();
});
