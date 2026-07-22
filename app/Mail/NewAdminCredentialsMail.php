<?php

namespace App\Mail;

use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAdminCredentialsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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
