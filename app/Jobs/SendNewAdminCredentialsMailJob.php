<?php

namespace App\Jobs;

use App\Mail\NewAdminCredentialsMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewAdminCredentialsMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $password,
    ) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->use(Tenant::findOrFail($this->tenantId));

        $user = User::findOrFail($this->userId);

        Mail::to($user->email)->send(new NewAdminCredentialsMail($user, $this->password));
    }
}
