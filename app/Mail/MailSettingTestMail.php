<?php

namespace App\Mail;

use App\Models\ClubSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MailSettingTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test de configuration mail — '.(ClubSetting::current()?->name ?? 'RC Cotonou Ife'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mail-setting-test',
        );
    }
}
