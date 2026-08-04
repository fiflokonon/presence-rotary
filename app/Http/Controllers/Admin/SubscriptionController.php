<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\TenantContext;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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
}
