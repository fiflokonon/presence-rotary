<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        Log::info('resolve_tenant.enter', [
            'host' => $request->getHost(),
            'path' => $request->path(),
            'sqlite_before' => config('database.connections.sqlite.database'),
        ]);

        $tenant = $request->getHost() === config('tenancy.super_admin_host')
            ? $this->resolveImpersonatedTenant($request)
            : Tenant::where('host', $request->getHost())->first();

        abort_if($tenant === null, 404);

        $this->tenantContext->use($tenant);

        Log::info('resolve_tenant.resolved', [
            'host' => $request->getHost(),
            'path' => $request->path(),
            'tenant_id' => $tenant->id,
            'sqlite_after' => config('database.connections.sqlite.database'),
        ]);

        return $next($request);
    }

    private function resolveImpersonatedTenant(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get('impersonating_tenant_id');

        return $tenantId !== null ? Tenant::find($tenantId) : null;
    }
}
