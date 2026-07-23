<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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
        $previousTenant = $this->tenantContext->current();

        $directory = database_path('data/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $sqlitePath = $directory.'/'.Str::uuid().'.sqlite';
        touch($sqlitePath);

        $this->tenantContext->use(new Tenant([...$request->validated(), 'sqlite_path' => $sqlitePath]));
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $tenant = Tenant::create([
            ...$request->validated(),
            'sqlite_path' => $sqlitePath,
        ]);

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }
}
