<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $password = Str::password(16);

        $user = User::create([
            ...$request->validated(),
            'password' => $password,
        ]);

        SendNewAdminCredentialsMailJob::dispatch($this->tenantContext->current()->id, $user->id, $password);

        return redirect()->route('admin.users.index');
    }
}
