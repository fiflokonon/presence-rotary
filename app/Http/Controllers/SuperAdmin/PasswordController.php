<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SuperAdminUpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('super-admin.password.edit');
    }

    public function update(SuperAdminUpdatePasswordRequest $request): RedirectResponse
    {
        Auth::guard('super_admin')->user()->update([
            'password' => $request->validated('password'),
        ]);

        return back()->with('status', 'Mot de passe mis à jour.');
    }
}
