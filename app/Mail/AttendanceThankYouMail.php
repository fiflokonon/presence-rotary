<?php

namespace App\Mail;

use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AttendanceThankYouMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Attendance $attendance,
        public MeetingSession $meetingSession,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Merci pour votre présence — RC Cotonou Nexus',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.attendance-thank-you',
        );
    }
}
