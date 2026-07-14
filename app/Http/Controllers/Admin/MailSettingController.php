<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMailSettingTestRequest;
use App\Http\Requests\StoreMailSettingRequest;
use App\Mail\MailSettingTestMail;
use App\Models\MailSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

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

    public function sendTest(SendMailSettingTestRequest $request): RedirectResponse
    {
        if (MailSetting::current() === null) {
            return back()->withErrors(['test_email' => "Enregistrez d'abord une configuration."]);
        }

        try {
            Mail::to($request->validated('test_email'))->send(new MailSettingTestMail);
        } catch (Throwable $e) {
            return back()->withErrors(['test_email' => 'Échec de l\'envoi : '.$e->getMessage()]);
        }

        return back()->with('status', 'Mail de test envoyé.');
    }
}
