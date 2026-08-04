<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignupRequest;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use App\Services\SubscriptionActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SignupController extends Controller
{
    public function __construct(private readonly PayPlusGateway $gateway) {}

    public function show(): View
    {
        return view('signup.show', [
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $reference = 'SUB-'.strtoupper(Str::random(12));

        $result = $this->gateway->initiate(
            amount: $plan->price,
            description: "Abonnement {$plan->name}",
            phone: $request->validated('phone'),
            customerFirstName: $request->validated('admin_name'),
            customerLastName: $request->validated('admin_name'),
            customerEmail: $request->validated('admin_email'),
            customData: ['reference' => $reference],
        );

        if (! $result['success']) {
            return redirect()->route('signup.show')->with('error', $result['message']);
        }

        Transaction::create([
            'tenant_id' => null,
            'plan_id' => $plan->id,
            'reference' => $reference,
            'amount' => $plan->price,
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => $request->validated('payment_method'),
            'payment_token' => $result['token'],
            'metadata' => [
                'club_name' => $request->validated('club_name'),
                'admin_name' => $request->validated('admin_name'),
                'admin_email' => $request->validated('admin_email'),
            ],
        ]);

        return redirect()->route('signup.pending', ['token' => $result['token']]);
    }

    public function pending(): View
    {
        return view('signup.pending', ['token' => request()->query('token')]);
    }

    public function checkPaymentStatus(SubscriptionActivationService $activationService): JsonResponse
    {
        return response()->json($activationService->activateFromToken(request()->query('token')));
    }
}
