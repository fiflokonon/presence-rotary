<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Mail\NewAdminCredentialsMail;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Mail;

it('sends the credentials mail for the given tenant and user', function () {
    Mail::fake();
    $tenantId = app(TenantContext::class)->current()->id;

    $user = User::factory()->create(['email' => 'new-admin@example.com']);

    (new SendNewAdminCredentialsMailJob($tenantId, $user->id, 'temp-password'))->handle(app(TenantContext::class));

    Mail::assertSent(NewAdminCredentialsMail::class, fn (NewAdminCredentialsMail $mail) => $mail->hasTo('new-admin@example.com')
        && $mail->user->is($user)
        && $mail->password === 'temp-password');
});
