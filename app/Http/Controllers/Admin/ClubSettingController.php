<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClubSettingRequest;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ClubSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.club-settings.edit', [
            'clubSetting' => ClubSetting::current(),
        ]);
    }

    public function update(UpdateClubSettingRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('logo');
        $clubSetting = ClubSetting::current();

        if ($request->hasFile('logo')) {
            if ($clubSetting?->logo_path) {
                Storage::disk('public')->delete($clubSetting->logo_path);
            }

            $data['logo_path'] = $request->file('logo')->store('club', 'public');
        }

        if ($clubSetting !== null) {
            $clubSetting->update($data);
        } else {
            ClubSetting::create($data);
        }

        return redirect()->route('admin.club-settings.edit')->with('status', 'Identité du club enregistrée.');
    }
}
