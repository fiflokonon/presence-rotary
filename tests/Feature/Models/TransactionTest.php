<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Database\QueryException;

it('belongs to a tenant and a plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    expect($transaction->tenant->id)->toBe($tenant->id)
        ->and($transaction->plan->id)->toBe($plan->id)
        ->and($transaction->status)->toBe(Transaction::STATUS_PENDING);
});

it('allows a null tenant for self-service signups, carrying provisioning data in metadata', function () {
    $transaction = Transaction::factory()->selfService()->create();

    expect($transaction->tenant_id)->toBeNull()
        ->and($transaction->metadata['club_name'])->toBe('Rotary Club Test');
});

it('enforces a unique reference', function () {
    Transaction::factory()->create(['reference' => 'SUB-DUPLICATE']);

    expect(fn () => Transaction::factory()->create(['reference' => 'SUB-DUPLICATE']))
        ->toThrow(QueryException::class);
});
