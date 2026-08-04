<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'reference' => 'SUB-'.strtoupper(Str::random(12)),
            'amount' => 5000,
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => 'mtn_momo',
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => Transaction::STATUS_COMPLETED, 'paid_at' => now()]);
    }

    public function selfService(): static
    {
        return $this->state([
            'tenant_id' => null,
            'metadata' => [
                'club_name' => 'Rotary Club Test',
                'admin_name' => 'Admin Test',
                'admin_email' => 'admin@example.test',
            ],
        ]);
    }
}
