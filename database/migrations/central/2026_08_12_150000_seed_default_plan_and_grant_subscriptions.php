<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\DefaultSubscriptionGranter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $plan = Plan::firstOrCreate(
            ['name' => 'Trimestriel', 'duration_months' => 3],
            ['price' => 15000, 'is_active' => true],
        );

        app(DefaultSubscriptionGranter::class)->grantOfferedPlanToAllTenants($plan);
    }

    public function down(): void
    {
        $plan = Plan::where('name', 'Trimestriel')->where('duration_months', 3)->first();

        if ($plan === null) {
            return;
        }

        Subscription::where('plan_id', $plan->id)
            ->where('source', Subscription::SOURCE_OFFERED)
            ->delete();

        $plan->delete();
    }
};
