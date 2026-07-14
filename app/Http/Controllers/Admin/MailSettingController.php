<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailSettingRequest;
use App\Models\MailSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MailSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.mail-settings.edit', [
            'mailSetting' => MailSetting::current(),
        ]);
    }

    public function update(StoreMailSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $mailSetting = MailSetting::current();

        if ($mailSetting !== null) {
            $mailSetting->update($data);
        } else {
            MailSetting::create($data);
        }

        return redirect()->route('admin.mail-settings.edit')->with('status', 'Paramètres mail enregistrés.');
    }
}
