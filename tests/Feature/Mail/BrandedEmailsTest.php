<?php

use App\Mail\AttendanceThankYouMail;
use App\Mail\MailSettingTestMail;
use App\Mail\NewAdminCredentialsMail;
use App\Models\Attendance;
use App\Models\ClubSetting;
use App\Models\MeetingSession;
use App\Models\User;

beforeEach(function () {
    ClubSetting::current()->update([
        'name' => 'Club Branding Test',
        'tagline' => 'Zone Test',
        'primary_color' => '#654321',
        'address' => '1 rue Exemple',
        'phone' => '+229 11 11 11 11',
        'email' => 'contact@example.test',
        'website' => 'https://example.test',
        'facebook_url' => 'https://facebook.com/example',
        'instagram_url' => 'https://instagram.com/example',
    ]);
});

it('renders the configured branding and footer in the thank-you email', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertHasSubject('Merci pour votre présence — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
    $mailable->assertSeeInHtml('Zone Test');
    $mailable->assertSeeInHtml('1 rue Exemple');
    $mailable->assertSeeInHtml('+229 11 11 11 11');
    $mailable->assertSeeInHtml('Facebook');
    $mailable->assertSeeInHtml('Instagram');
});

it('renders the configured branding in the new admin credentials email', function () {
    $mailable = new NewAdminCredentialsMail(User::factory()->create(['name' => 'Awa Bello']), 'temp-password');

    $mailable->assertHasSubject('Vos identifiants d\'administration — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
    $mailable->assertSeeInHtml('1 rue Exemple');
});

it('renders the configured branding in the mail test email', function () {
    $mailable = new MailSettingTestMail;

    $mailable->assertHasSubject('Test de configuration mail — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
});
