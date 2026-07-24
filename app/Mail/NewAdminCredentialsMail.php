<?php

namespace App\Mail;

use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewAdminCredentialsMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $password,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos identifiants d\'administration — '.(ClubSetting::current()?->name ?? 'RC Cotonou Ife'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.new-admin-credentials',
        );
    }
}
