<?php

use App\Services\PayPlusGateway;
use Illuminate\Support\Facades\Http;

it('fails fast without calling the network when PayPlus credentials are not configured', function () {
    config(['payplus.api_key' => '', 'payplus.token' => '']);

    $result = app(PayPlusGateway::class)->initiate(
        amount: 5000,
        description: 'Abonnement Mensuel',
        phone: '90000000',
        customerFirstName: 'Admin',
        customerLastName: 'Test',
        customerEmail: 'admin@example.test',
        customData: ['reference' => 'SUB-TEST'],
    );

    expect($result)->toBe(['success' => false, 'message' => 'Configuration PayPlus manquante']);
});

it('reports a failed HTTP call when fetching status', function () {
    Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => Http::response(null, 500),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('some-token');

    expect($result['success'])->toBeFalse();
});

it('parses a completed status response, normalizing custom_data', function () {
    Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => Http::response([
            'response_code' => '00',
            'status' => 'completed',
            'montant' => 5000,
            'custom_data' => [
                ['keyof_customdata' => 'reference', 'valueof_customdata' => 'SUB-TEST'],
            ],
        ], 200),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('some-token');

    expect($result)->toBe([
        'success' => true,
        'status' => 'completed',
        'amount' => 5000,
        'custom_data' => ['reference' => 'SUB-TEST'],
    ]);
});

it('reports failure when PayPlus responds with a non-00 response code', function () {
    Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => Http::response([
            'response_code' => '01',
            'response_text' => 'Invoice not found',
        ], 200),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('bad-token');

    expect($result)->toBe(['success' => false, 'message' => 'Invoice not found']);
});
