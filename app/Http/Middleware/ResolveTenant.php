<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->getHost() === config('tenancy.super_admin_host')
            ? $this->resolveImpersonatedTenant($request)
            : Tenant::where('host', $request->getHost())->first();

        abort_if($tenant === null, 404);

        $this->tenantContext->use($tenant);

        return $next($request);
    }

    private function resolveImpersonatedTenant(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get('impersonating_tenant_id');

        return $tenantId !== null ? Tenant::find($tenantId) : null;
    }
}
