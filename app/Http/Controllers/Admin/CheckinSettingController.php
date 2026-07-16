<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCheckinSettingRequest;
use App\Models\CheckinSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CheckinSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.checkin-settings.edit', [
            'checkinSetting' => CheckinSetting::current(),
        ]);
    }

    public function update(UpdateCheckinSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $checkinSetting = CheckinSetting::current();

        if ($checkinSetting !== null) {
            $checkinSetting->update($data);
        } else {
            CheckinSetting::create($data);
        }

        return redirect()->route('admin.checkin-settings.edit')->with('status', 'Paramètres enregistrés.');
    }
}
