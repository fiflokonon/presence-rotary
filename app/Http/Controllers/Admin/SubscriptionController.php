<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckoutSubscriptionRequest;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use App\Services\SubscriptionActivationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PayPlusGateway $gateway,
        private readonly SubscriptionActivationService $activationService,
    ) {}

    public function index(): View
    {
        $tenant = $this->tenantContext->current();

        return view('admin.subscription.index', [
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentSubscription(),
            'accessState' => $tenant->accessState(),
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
            'history' => $tenant->subscriptions()->with('plan')->orderByDesc('end_date')->get(),
        ]);
    }

    public function checkout(CheckoutSubscriptionRequest $request): RedirectResponse
    {
        $tenant = $this->tenantContext->current();
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $reference = 'SUB-'.strtoupper(Str::random(12));

        $result = $this->gateway->initiate(
            amount: $plan->price,
            description: "Abonnement {$plan->name}",
            phone: $request->validated('phone'),
            customerFirstName: $tenant->name,
            customerLastName: $tenant->name,
            customerEmail: '',
            customData: ['reference' => $reference],
        );

        if (! $result['success']) {
            return redirect()->route('admin.subscription.index')->with('error', $result['message']);
        }

        Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'reference' => $reference,
            'amount' => $plan->price,
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => $request->validated('payment_method'),
            'payment_token' => $result['token'],
        ]);

        return redirect()->route('admin.subscription.pending', ['token' => $result['token']]);
    }

    public function pending(): View
    {
        return view('admin.subscription.pending', [
            'token' => request()->query('token'),
        ]);
    }

    public function checkPaymentStatus(): JsonResponse
    {
        $result = $this->activationService->activateFromToken(request()->query('token'));

        return response()->json($result);
    }
}
