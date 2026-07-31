<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('admin.password.edit');
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        Auth::guard('web')->user()->update([
            'password' => $request->validated('password'),
        ]);

        return back()->with('status', 'Mot de passe mis à jour.');
    }
}
