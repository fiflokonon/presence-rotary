<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now();

        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'transaction_id' => null,
            'source' => Subscription::SOURCE_OFFERED,
            'amount' => 0,
            'start_date' => $start,
            'end_date' => $start->copy()->addMonth(),
        ];
    }

    public function expiredDaysAgo(int $days): static
    {
        return $this->state(fn () => [
            'start_date' => now()->subMonth()->subDays($days),
            'end_date' => now()->subDays($days),
        ]);
    }
}
