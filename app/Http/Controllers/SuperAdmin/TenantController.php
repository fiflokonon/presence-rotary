<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
use App\Http\Requests\SuperAdmin\UpdateGracePeriodRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function __construct(private readonly TenantProvisioningService $provisioningService) {}

    public function index(): View
    {
        return view('super-admin.tenants.index', [
            'tenants' => Tenant::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.tenants.create', [
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
        ]);
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $tenant = $this->provisioningService->provision(
            $request->validated('name'),
            $request->validated('host'),
            $request->validated('admin_name'),
            $request->validated('admin_email'),
        );

        $plan = Plan::findOrFail($request->validated('plan_id'));

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'transaction_id' => null,
            'source' => Subscription::SOURCE_OFFERED,
            'amount' => 0,
            'start_date' => now(),
            'end_date' => now()->addMonths($plan->duration_months),
        ]);

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }

    public function updateGracePeriod(UpdateGracePeriodRequest $request): RedirectResponse
    {
        Tenant::whereIn('id', $request->validated('tenant_ids'))
            ->update(['grace_period_days' => $request->validated('grace_period_days')]);

        return redirect()->route('super-admin.tenants.index')->with('status', 'Délai de grâce mis à jour.');
    }
}
