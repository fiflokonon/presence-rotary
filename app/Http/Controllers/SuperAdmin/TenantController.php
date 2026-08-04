<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
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
        return view('super-admin.tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $this->provisioningService->provision(
            $request->validated('name'),
            $request->validated('host'),
            $request->validated('admin_name'),
            $request->validated('admin_email'),
        );

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }
}
