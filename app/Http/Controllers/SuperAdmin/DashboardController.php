<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        $previousTenant = $this->tenantContext->current();

        $rows = Tenant::orderBy('name')->get()->map(function (Tenant $tenant): array {
            $this->tenantContext->use($tenant);

            return [
                'name' => $tenant->name,
                'member_count' => Member::count(),
                'attendance_count' => Attendance::where('present', true)->count(),
            ];
        });

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return view('super-admin.dashboard', ['rows' => $rows]);
    }
}
