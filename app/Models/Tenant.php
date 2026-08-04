<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $connection = 'central';

    protected $fillable = ['name', 'host', 'sqlite_path', 'grace_period_days'];

    public const ACCESS_ACTIVE = 'active';

    public const ACCESS_GRACE = 'grace';

    public const ACCESS_BLOCKED = 'blocked';

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()->orderByDesc('end_date')->first();
    }

    public function accessState(): string
    {
        $subscription = $this->currentSubscription();

        if ($subscription === null) {
            return self::ACCESS_BLOCKED;
        }

        if (now()->lt($subscription->end_date)) {
            return self::ACCESS_ACTIVE;
        }

        $graceDays = $this->grace_period_days ?? PlatformSetting::current()?->default_grace_period_days ?? 0;
        $graceEndsAt = $subscription->end_date->copy()->addDays($graceDays);

        return now()->lt($graceEndsAt) ? self::ACCESS_GRACE : self::ACCESS_BLOCKED;
    }
}
