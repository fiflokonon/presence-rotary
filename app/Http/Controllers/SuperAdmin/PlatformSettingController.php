<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdatePlatformSettingRequest;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlatformSettingController extends Controller
{
    public function edit(): View
    {
        return view('super-admin.settings.edit', [
            'platformSetting' => PlatformSetting::current(),
        ]);
    }

    public function update(UpdatePlatformSettingRequest $request): RedirectResponse
    {
        PlatformSetting::current()->update($request->validated());

        return redirect()->route('super-admin.settings.edit')->with('status', 'Réglages enregistrés.');
    }
}
