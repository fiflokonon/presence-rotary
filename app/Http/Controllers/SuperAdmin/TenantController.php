<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\Tenant;
use App\Models\User;
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

        $this->tenantContext->use(new Tenant([
            'name' => $request->validated('name'),
            'host' => $request->validated('host'),
            'sqlite_path' => $sqlitePath,
        ]));
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $tenant = Tenant::create([
            'name' => $request->validated('name'),
            'host' => $request->validated('host'),
            'sqlite_path' => $sqlitePath,
        ]);

        $password = Str::password(16);

        $admin = User::create([
            'name' => $request->validated('admin_name'),
            'email' => $request->validated('admin_email'),
            'password' => $password,
        ]);

        SendNewAdminCredentialsMailJob::dispatch($tenant->id, $admin->id, $password);

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }
}
