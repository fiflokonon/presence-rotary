<?php

namespace App\Http\Middleware;

use App\Models\ClubSetting;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantSubscription
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('super_admin')->check()) {
            return $next($request);
        }

        $tenant = $this->tenantContext->current();
        $state = $tenant->accessState();

        if ($state === Tenant::ACCESS_GRACE) {
            $this->shareGraceWarning($tenant);
        }

        if ($state !== Tenant::ACCESS_BLOCKED) {
            return $next($request);
        }

        if ($request->routeIs('admin.*')) {
            return redirect()->route('admin.subscription.index');
        }

        return response()->view('attendance.service-unavailable', [
            'clubSetting' => ClubSetting::current(),
        ]);
    }

    private function shareGraceWarning(Tenant $tenant): void
    {
        $subscription = $tenant->currentSubscription();
        $graceDays = $tenant->grace_period_days ?? PlatformSetting::current()?->default_grace_period_days ?? 0;

        View::share('subscriptionGraceWarning', [
            'expiredAt' => $subscription->end_date,
            'graceEndsAt' => $subscription->end_date->copy()->addDays($graceDays),
        ]);
    }
}
