<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPlusCallbackController extends Controller
{
    public function __construct(private readonly SubscriptionActivationService $activationService) {}

    public function handle(Request $request): JsonResponse
    {
        $token = $request->input('token');

        if (blank($token)) {
            return response()->json(['status' => 'error', 'message' => 'Token manquant'], 400);
        }

        if ($request->input('response_code') !== '00') {
            return response()->json(['status' => 'error'], 400);
        }

        $result = $this->activationService->activateFromToken($token);

        if (! $result['success']) {
            return response()->json(['status' => 'error'], 400);
        }

        return response()->json(['status' => $result['status'] === 'completed' ? 'success' : $result['status']]);
    }
}
