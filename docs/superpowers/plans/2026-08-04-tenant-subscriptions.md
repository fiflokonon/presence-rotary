# Tenant Subscriptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate a tenant's access to the service behind a subscription (paid via PayPlus Africa mobile money, or offered free by the super-admin), renewable by a club's own admin, purchasable self-service by a new club, and managed (plans, grace periods) by the super-admin.

**Architecture:** All billing data (`plans`, `transactions`, `subscriptions`, `platform_settings`) lives on the existing `central` Eloquent connection alongside `tenants`/`super_admins` — never on a tenant's own SQLite database — so payment processing never needs `TenantContext::use()`. A new `CheckTenantSubscription` middleware, layered after the existing `ResolveTenant`, computes access state from dates (no stored status enum) and blocks/warns per §3 of the spec. PayPlus integration reuses the `payplus-africa/payplus` composer package exactly as proven in the `linkfolio` project's "straight" (no-redirect) mobile-money flow.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, `payplus-africa/payplus` (dev-main), SQLite (central + per-tenant).

## Global Constraints

- Reuse the PayPlus integration pattern from `linkfolio`'s `SubscriptionController` — the `payplus-africa/payplus` package, its "straight" (no-redirect) flow, and the dual poll+webhook confirmation via `Http::get()` to `{base_url}/pay/v01/straight/checkout-invoice/confirm?invoiceToken={token}`.
- No promo codes, refunds, cashback, invoicing, card payments, or manual cancellation — out of scope per the spec.
- `subscriptions` has no `status` column — access state is always computed from `end_date` + grace period, never stored.
- The `PayPlusGateway::initiate()` call goes through the vendor package's raw-Guzzle `launchPaiement()` and is **not** interceptable by `Http::fake()` — every feature test that needs a successful checkout mocks `PayPlusGateway` itself via Laravel's `$this->mock()`, not `Http::fake()`. Only the missing-config guard branch and `fetchStatus()` (which uses `Http::` directly) get real HTTP-level tests.
- Currency is FCFA (XOF), matching PayPlus Africa and the `linkfolio` precedent — amounts are plain integers, no decimals.
- Follow existing conventions: FormRequests for validation, `redirect()->route(...)->with('status'/'error', ...)` for controller responses, Pest `it(...)` tests in `tests/Feature/**`, `vendor/bin/pint --dirty --format agent` after every PHP change.

---

## File Structure

```
database/migrations/central/
  2026_08_04_140000_create_plans_table.php
  2026_08_04_140100_create_transactions_table.php
  2026_08_04_140200_create_subscriptions_table.php
  2026_08_04_140300_create_platform_settings_table.php
  2026_08_04_140301_seed_platform_settings_table.php
  2026_08_04_140400_add_grace_period_days_to_tenants_table.php

app/Models/
  Plan.php, Transaction.php, Subscription.php, PlatformSetting.php   (new, central connection)
  Tenant.php                                                          (modified: subscriptions(), currentSubscription(), accessState())

database/factories/
  PlanFactory.php, TransactionFactory.php, SubscriptionFactory.php   (new)

app/Services/
  TenantProvisioningService.php   (new — extracted from TenantController::store)
  PayPlusGateway.php              (new)
  SubscriptionActivationService.php (new)

app/Http/Middleware/
  CheckTenantSubscription.php     (new)

app/Http/Controllers/
  Admin/SubscriptionController.php     (new)
  PayPlusCallbackController.php        (new)
  SignupController.php                 (new)
  SuperAdmin/PlanController.php        (new)
  SuperAdmin/PlatformSettingController.php (new)
  SuperAdmin/TenantController.php      (modified)

app/Http/Requests/
  Admin/CheckoutSubscriptionRequest.php (new)
  SuperAdmin/StorePlanRequest.php, UpdatePlanRequest.php (new)
  SuperAdmin/UpdatePlatformSettingRequest.php (new)
  SuperAdmin/StoreTenantRequest.php (modified: + plan_id)
  SuperAdmin/UpdateGracePeriodRequest.php (new)
  SignupRequest.php (new)

config/
  payplus.php   (new)
  tenancy.php   (modified: + base_domain)

routes/web.php  (modified extensively — see Task 6 and later tasks)

resources/views/
  admin/subscription/index.blade.php, pending.blade.php  (new)
  attendance/service-unavailable.blade.php                (new)
  super-admin/plans/index.blade.php, create.blade.php, edit.blade.php (new)
  super-admin/settings/edit.blade.php (new)
  super-admin/tenants/index.blade.php, create.blade.php (modified)
  signup/show.blade.php (new)
  super-admin/welcome.blade.php (modified)
  components/layouts/admin.blade.php (modified: grace banner + nav link)

composer.json  (modified: + payplus-africa/payplus)
.env.example   (modified: + PAYPLUS_*, TENANT_BASE_DOMAIN)
```

---

## Task 1: `Plan` model — the billing-tier catalog

**Files:**
- Create: `database/migrations/central/2026_08_04_140000_create_plans_table.php`
- Create: `app/Models/Plan.php`
- Create: `database/factories/PlanFactory.php`
- Test: `tests/Feature/Models/PlanTest.php`

**Interfaces:**
- Produces: `Plan` model, table `plans` (central), columns `id, name, duration_months, price, is_active, timestamps`. `Plan::factory()` available in tests.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('plans');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $connection = 'central';

    protected $fillable = ['name', 'duration_months', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Mensuel',
            'duration_months' => 1,
            'price' => 5000,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
```

- [ ] **Step 4: Write the failing test**

```php
<?php

use App\Models\Plan;

it('creates a plan with the expected attributes', function () {
    $plan = Plan::factory()->create(['name' => 'Annuel', 'duration_months' => 12, 'price' => 50000]);

    expect($plan->name)->toBe('Annuel')
        ->and($plan->duration_months)->toBe(12)
        ->and($plan->price)->toBe(50000)
        ->and($plan->is_active)->toBeTrue();
});

it('can be marked inactive via the factory state', function () {
    $plan = Plan::factory()->inactive()->create();

    expect($plan->is_active)->toBeFalse();
});
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `php artisan test --compact --filter=PlanTest`
Expected: FAIL — `Plan` class/table does not exist yet (this should already be resolved by steps 1-3; if it fails only because of a typo, fix and re-run).

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=PlanTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/central/2026_08_04_140000_create_plans_table.php app/Models/Plan.php database/factories/PlanFactory.php tests/Feature/Models/PlanTest.php
git commit -m "feat: add Plan model for the subscription billing-tier catalog"
```

---

## Task 2: `Transaction` model — one row per payment attempt

**Files:**
- Create: `database/migrations/central/2026_08_04_140100_create_transactions_table.php`
- Create: `app/Models/Transaction.php`
- Create: `database/factories/TransactionFactory.php`
- Test: `tests/Feature/Models/TransactionTest.php`

**Interfaces:**
- Consumes: `Plan` (Task 1), `Tenant` (existing, central).
- Produces: `Transaction` model, table `transactions` (central), columns `id, tenant_id (nullable), plan_id, reference (unique), amount, status, payment_method (nullable), payment_token (nullable), paid_at (nullable), metadata (json, nullable), timestamps`. Relations `tenant(): BelongsTo`, `plan(): BelongsTo`. Constants `Transaction::STATUS_PENDING = 'pending'`, `STATUS_COMPLETED = 'completed'`, `STATUS_FAILED = 'failed'`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('reference')->unique();
            $table->unsignedInteger('amount');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('payment_token')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('transactions');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $connection = 'central';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'plan_id', 'reference', 'amount', 'status',
        'payment_method', 'payment_token', 'paid_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
```

- [ ] **Step 3: Write the factory**

```php
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
```

- [ ] **Step 4: Write the failing test**

```php
<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;

it('belongs to a tenant and a plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    expect($transaction->tenant->id)->toBe($tenant->id)
        ->and($transaction->plan->id)->toBe($plan->id)
        ->and($transaction->status)->toBe(Transaction::STATUS_PENDING);
});

it('allows a null tenant for self-service signups, carrying provisioning data in metadata', function () {
    $transaction = Transaction::factory()->selfService()->create();

    expect($transaction->tenant_id)->toBeNull()
        ->and($transaction->metadata['club_name'])->toBe('Rotary Club Test');
});

it('enforces a unique reference', function () {
    Transaction::factory()->create(['reference' => 'SUB-DUPLICATE']);

    expect(fn () => Transaction::factory()->create(['reference' => 'SUB-DUPLICATE']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 5: Run the test, verify it fails, then implement until it passes**

Run: `php artisan test --compact --filter=TransactionTest`
Expected first: FAIL (model/migration missing) → after steps 1-3, PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/central/2026_08_04_140100_create_transactions_table.php app/Models/Transaction.php database/factories/TransactionFactory.php tests/Feature/Models/TransactionTest.php
git commit -m "feat: add Transaction model for subscription payment attempts"
```

---

## Task 3: `Subscription` model — one row per billing period granted

**Files:**
- Create: `database/migrations/central/2026_08_04_140200_create_subscriptions_table.php`
- Create: `app/Models/Subscription.php`
- Create: `database/factories/SubscriptionFactory.php`
- Test: `tests/Feature/Models/SubscriptionTest.php`

**Interfaces:**
- Consumes: `Plan` (Task 1), `Transaction` (Task 2), `Tenant` (existing).
- Produces: `Subscription` model, table `subscriptions` (central), columns `id, tenant_id, plan_id, transaction_id (nullable), source, amount, start_date, end_date, timestamps`. Relations `tenant(): BelongsTo`, `plan(): BelongsTo`, `transaction(): BelongsTo`. Constants `Subscription::SOURCE_PAID = 'paid'`, `SOURCE_OFFERED = 'offered'`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('source');
            $table->unsignedInteger('amount');
            $table->timestamp('start_date');
            $table->timestamp('end_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscriptions');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $connection = 'central';

    public const SOURCE_PAID = 'paid';

    public const SOURCE_OFFERED = 'offered';

    protected $fillable = [
        'tenant_id', 'plan_id', 'transaction_id', 'source', 'amount', 'start_date', 'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
```

- [ ] **Step 3: Write the factory**

```php
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
```

- [ ] **Step 4: Write the failing test**

```php
<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

it('belongs to a tenant and a plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    expect($subscription->tenant->id)->toBe($tenant->id)
        ->and($subscription->plan->id)->toBe($plan->id)
        ->and($subscription->source)->toBe(Subscription::SOURCE_OFFERED);
});

it('supports the expiredDaysAgo factory state', function () {
    $subscription = Subscription::factory()->expiredDaysAgo(3)->create();

    expect($subscription->end_date->isPast())->toBeTrue()
        ->and($subscription->end_date->diffInDays(now()))->toBeGreaterThanOrEqual(3);
});
```

- [ ] **Step 5: Run the test, verify it fails, then implement until it passes**

Run: `php artisan test --compact --filter=SubscriptionTest`

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/central/2026_08_04_140200_create_subscriptions_table.php app/Models/Subscription.php database/factories/SubscriptionFactory.php tests/Feature/Models/SubscriptionTest.php
git commit -m "feat: add Subscription model for granted billing periods"
```

---

## Task 4: `PlatformSetting` — the global default grace period

**Files:**
- Create: `database/migrations/central/2026_08_04_140300_create_platform_settings_table.php`
- Create: `database/migrations/central/2026_08_04_140301_seed_platform_settings_table.php`
- Create: `app/Models/PlatformSetting.php`
- Test: `tests/Feature/Models/PlatformSettingTest.php`

**Interfaces:**
- Produces: `PlatformSetting` model, table `platform_settings` (central), single seeded row `default_grace_period_days = 7`. `PlatformSetting::current(): ?self` (same pattern as `ClubSetting::current()`).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('default_grace_period_days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_settings');
    }
};
```

- [ ] **Step 2: Write the seed migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        DB::connection('central')->table('platform_settings')->insert([
            'default_grace_period_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('central')->table('platform_settings')->truncate();
    }
};
```

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $connection = 'central';

    protected $fillable = ['default_grace_period_days'];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
```

- [ ] **Step 4: Write the failing test**

```php
<?php

use App\Models\PlatformSetting;

it('has a seeded default grace period of 7 days', function () {
    expect(PlatformSetting::current()->default_grace_period_days)->toBe(7);
});
```

- [ ] **Step 5: Run the test, verify it fails, then implement until it passes**

Run: `php artisan test --compact --filter=PlatformSettingTest`

Note: this relies on `tests/TestCase.php`'s `setUp()` already running `migrate --database=central --path=database/migrations/central --force` (see the file read during planning), so the new seed migration runs automatically for every Feature test — no test harness changes needed.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/central/2026_08_04_140300_create_platform_settings_table.php database/migrations/central/2026_08_04_140301_seed_platform_settings_table.php app/Models/PlatformSetting.php tests/Feature/Models/PlatformSettingTest.php
git commit -m "feat: add PlatformSetting singleton for the default grace period"
```

---

## Task 5: `Tenant::accessState()` — the access-state computation

**Files:**
- Create: `database/migrations/central/2026_08_04_140400_add_grace_period_days_to_tenants_table.php`
- Modify: `app/Models/Tenant.php`
- Test: `tests/Feature/Models/TenantAccessStateTest.php`

**Interfaces:**
- Consumes: `Subscription` (Task 3), `PlatformSetting` (Task 4).
- Produces: `Tenant::subscriptions(): HasMany`, `Tenant::currentSubscription(): ?Subscription`, `Tenant::accessState(): string`, constants `Tenant::ACCESS_ACTIVE = 'active'`, `Tenant::ACCESS_GRACE = 'grace'`, `Tenant::ACCESS_BLOCKED = 'blocked'`. `Tenant::grace_period_days` (nullable int column).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_period_days')->nullable()->after('sqlite_path');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('grace_period_days');
        });
    }
};
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;

it('is active while now is before the subscription end date', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(10),
    ]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);
});

it('is in grace when past end date but within the platform default grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
    $tenant = Tenant::factory()->create(['grace_period_days' => null]);
    Subscription::factory()->expiredDaysAgo(3)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_GRACE);
});

it('is blocked once past end date and the grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
    $tenant = Tenant::factory()->create(['grace_period_days' => null]);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_BLOCKED);
});

it('prefers a per-tenant grace_period_days override over the platform default', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 1]);
    $tenant = Tenant::factory()->create(['grace_period_days' => 30]);
    Subscription::factory()->expiredDaysAgo(5)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_GRACE);
});

it('is blocked when it has no subscription at all', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->accessState())->toBe(Tenant::ACCESS_BLOCKED);
});

it('uses the subscription with the latest end date as current', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now()->subMonths(2),
        'end_date' => now()->subMonth(),
    ]);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now(),
        'end_date' => now()->addMonth(),
    ]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TenantAccessStateTest`
Expected: FAIL — `Tenant::accessState()` does not exist.

- [ ] **Step 4: Implement `accessState()` on `Tenant`**

Add to `app/Models/Tenant.php` (alongside the existing `use HasFactory;` and `protected $fillable`):

```php
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
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
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=TenantAccessStateTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/central/2026_08_04_140400_add_grace_period_days_to_tenants_table.php app/Models/Tenant.php tests/Feature/Models/TenantAccessStateTest.php
git commit -m "feat: compute tenant access state from subscription dates and grace period"
```

---

## Task 6: `CheckTenantSubscription` middleware and route wiring

**Files:**
- Create: `app/Http/Middleware/CheckTenantSubscription.php`
- Create: `resources/views/attendance/service-unavailable.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/admin.blade.php` (grace banner)
- Test: `tests/Feature/CheckTenantSubscriptionTest.php`

**Interfaces:**
- Consumes: `Tenant::accessState()` (Task 5), `TenantContext::current()` (existing), `PlatformSetting` (Task 4).
- Produces: route name `admin.subscription.index` is referenced here but **not yet defined** — this task creates a stub route pointing at a placeholder closure returning a plain 200 response, upgraded to the real controller in Task 13. Shared view variable `subscriptionGraceWarning` (array `['expiredAt' => Carbon, 'graceEndsAt' => Carbon]` or absent).

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
});

it('shows the service-unavailable page on the public check-in form when the tenant is blocked', function () {
    $tenant = Tenant::factory()->create(['host' => 'blocked.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);

    $this->get('http://blocked.example.test/')
        ->assertOk()
        ->assertSee('indisponible', escape: false);
});

it('shows the public check-in form as normal when the tenant is active', function () {
    $tenant = Tenant::factory()->create(['host' => 'active.example.test']);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'end_date' => now()->addMonth(),
    ]);

    $this->get('http://active.example.test/')->assertOk()->assertDontSee('indisponible');
});

it('redirects a blocked admin to the subscription page instead of the dashboard', function () {
    $tenant = Tenant::factory()->create(['host' => 'blocked-admin.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://blocked-admin.example.test/admin/dashboard')
        ->assertRedirect('http://blocked-admin.example.test/admin/subscription');
});

it('lets a blocked admin still reach the subscription page', function () {
    $tenant = Tenant::factory()->create(['host' => 'blocked-admin2.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://blocked-admin2.example.test/admin/subscription')
        ->assertOk();
});

it('shows a grace banner but does not block a grace-period admin', function () {
    $tenant = Tenant::factory()->create(['host' => 'grace-admin.example.test']);
    Subscription::factory()->expiredDaysAgo(2)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://grace-admin.example.test/admin/dashboard')
        ->assertOk()
        ->assertSee('souscription a expiré', escape: false);
});

it('never blocks an authenticated super-admin, even while impersonating a blocked tenant', function () {
    $tenant = Tenant::factory()->create(['host' => 'blocked-super.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->withSession(['impersonating_tenant_id' => $tenant->id])
        ->get('http://blocked-super.example.test/admin/dashboard')
        ->assertOk();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CheckTenantSubscriptionTest`
Expected: FAIL — middleware/routes don't exist.

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\ClubSetting;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantSubscription
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('super_admin')->check()) {
            return $next($request);
        }

        $tenant = $this->tenantContext->current();
        $state = $tenant->accessState();

        if ($state === Tenant::ACCESS_GRACE) {
            $this->shareGraceWarning($tenant);
        }

        if ($state !== Tenant::ACCESS_BLOCKED) {
            return $next($request);
        }

        if ($request->routeIs('admin.*')) {
            return redirect()->route('admin.subscription.index');
        }

        return response()->view('attendance.service-unavailable', [
            'clubSetting' => ClubSetting::current(),
        ]);
    }

    private function shareGraceWarning(Tenant $tenant): void
    {
        $subscription = $tenant->currentSubscription();
        $graceDays = $tenant->grace_period_days ?? PlatformSetting::current()?->default_grace_period_days ?? 0;

        View::share('subscriptionGraceWarning', [
            'expiredAt' => $subscription->end_date,
            'graceEndsAt' => $subscription->end_date->copy()->addDays($graceDays),
        ]);
    }
}
```

- [ ] **Step 4: Write the service-unavailable view**

```blade
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $clubSetting?->name ?? 'Service' }} — Indisponible</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-cream font-sans text-navy antialiased">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-8 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        @if ($clubSetting)
            <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" class="mx-auto h-16 w-16 object-contain">
            <h1 class="mt-4 font-display text-lg font-extrabold text-navy">{{ $clubSetting->name }}</h1>
        @endif
        <p class="mt-3 text-sm text-muted">
            Ce service est temporairement indisponible. Merci de contacter l'administrateur du club.
        </p>
    </div>
</body>
</html>
```

- [ ] **Step 5: Restructure `routes/web.php`**

Replace the existing tenant-scoped route block with the following (the public check-in group and the admin group each nest inside their own `CheckTenantSubscription` sub-group; a placeholder `admin.subscription.index` route is added now and replaced by the real controller in Task 13):

```php
use App\Http\Middleware\CheckTenantSubscription;

Route::middleware(ResolveTenant::class)->group(function () {
    Route::middleware(CheckTenantSubscription::class)->group(function () {
        Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
        Route::post('/check-in', [AttendanceFormController::class, 'lookup'])->name('attendance.lookup');
        Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('login', [AuthController::class, 'create'])->name('login');
            Route::post('login', [AuthController::class, 'store'])->name('login.store');
        });

        Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
            Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

            Route::get('subscription', fn () => response('ok'))->name('subscription.index');

            Route::middleware(CheckTenantSubscription::class)->group(function () {
                Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
                Route::get('sessions', [MeetingSessionController::class, 'index'])->name('sessions.index');
                Route::post('sessions', [MeetingSessionController::class, 'store'])->name('sessions.store');
                Route::post('sessions/{meetingSession}/toggle-open', [MeetingSessionController::class, 'toggleOpen'])->name('sessions.toggle-open');
                Route::get('sessions/{meetingSession}', [MeetingSessionController::class, 'show'])->name('sessions.show');
                Route::get('sessions/{meetingSession}/export-pdf', [MeetingSessionController::class, 'exportPdf'])->name('sessions.export-pdf');
                Route::patch('attendances/{attendance}/toggle-present', [AttendanceController::class, 'togglePresent'])->name('attendances.toggle-present');
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/create', [UserController::class, 'create'])->name('users.create');
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::get('members', [MemberController::class, 'index'])->name('members.index');
                Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
                Route::post('members', [MemberController::class, 'store'])->name('members.store');
                Route::get('members/import-template', [MemberController::class, 'importTemplate'])->name('members.import-template');
                Route::post('members/import', [MemberController::class, 'import'])->name('members.import');
                Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
                Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
                Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
                Route::get('titles', [TitleController::class, 'index'])->name('titles.index');
                Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
                Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
                Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
                Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
                Route::patch('titles/{title}/toggle-active', [TitleController::class, 'toggleActive'])->name('titles.toggle-active');
                Route::patch('titles/{title}/move-order/{direction}', [TitleController::class, 'moveOrder'])->name('titles.move-order');
                Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
                Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
                Route::get('positions/create', [PositionController::class, 'create'])->name('positions.create');
                Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
                Route::get('positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
                Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
                Route::patch('positions/{position}/toggle-active', [PositionController::class, 'toggleActive'])->name('positions.toggle-active');
                Route::patch('positions/{position}/move-order/{direction}', [PositionController::class, 'moveOrder'])->name('positions.move-order');
                Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
                Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
                Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
                Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
                Route::get('checkin-settings', [CheckinSettingController::class, 'edit'])->name('checkin-settings.edit');
                Route::put('checkin-settings', [CheckinSettingController::class, 'update'])->name('checkin-settings.update');
                Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
                Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
            });
        });

        Route::middleware(['auth:web', 'auth.session.guard:web'])->group(function () {
            Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        });
    });
});
```

Everything inside the `CheckTenantSubscription::class` sub-groups is unchanged from the current file — only the wrapping groups are new. The `password.*` routes stay outside the check (a blocked admin should still be able to change their own password) and `subscription.index` stays outside it too (that's the whole point).

- [ ] **Step 6: Add the grace banner to the admin layout**

In `resources/views/components/layouts/admin.blade.php`, right after the existing impersonation banner block (`@if (session()->has('impersonating_tenant_id')) ... @endif`), add:

```blade
    @isset($subscriptionGraceWarning)
        <div class="bg-gold px-4 py-2 text-center text-sm font-semibold text-navy">
            Votre souscription a expiré le {{ $subscriptionGraceWarning['expiredAt']->format('d/m/Y') }}. Renouvelez avant le {{ $subscriptionGraceWarning['graceEndsAt']->format('d/m/Y') }} pour ne pas perdre l'accès.
            <a href="{{ route('admin.subscription.index') }}" class="underline">Renouveler</a>
        </div>
    @endisset
```

`@isset` reads the shared view variable set by the middleware in Step 3; it's absent (not just null) outside the grace state, so `@isset` — not `@if` — is the correct directive here.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CheckTenantSubscriptionTest`
Expected: PASS

- [ ] **Step 8: Run the full existing suite to catch regressions from the route restructure**

Run: `php artisan test --compact`
Expected: PASS — every previously-passing test still passes (the route names and behavior inside the `CheckTenantSubscription` groups are unchanged, only the wrapping changed).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/CheckTenantSubscription.php resources/views/attendance/service-unavailable.blade.php routes/web.php resources/views/components/layouts/admin.blade.php tests/Feature/CheckTenantSubscriptionTest.php
git commit -m "feat: block/warn tenant access based on subscription state"
```

---

## Task 7: Extract `TenantProvisioningService`

**Files:**
- Create: `app/Services/TenantProvisioningService.php`
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Test: `tests/Feature/Services/TenantProvisioningServiceTest.php`

**Interfaces:**
- Consumes: `TenantContext` (existing).
- Produces: `TenantProvisioningService::provision(string $name, string $host, string $adminName, string $adminEmail): Tenant`. Behavior-preserving extraction — no change to what `TenantController::store` does, only where the code lives.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('provisions a migrated tenant database with a first admin user', function () {
    Queue::fake();

    $tenant = app(TenantProvisioningService::class)->provision(
        'Rotary Club Provisioning',
        'provisioning.example.test',
        'Première Admin',
        'premiere.admin@provisioning.test',
    );

    expect($tenant->name)->toBe('Rotary Club Provisioning')
        ->and(Tenant::where('host', 'provisioning.example.test')->exists())->toBeTrue();

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');

    expect(Schema::hasTable('club_settings'))->toBeTrue();
    expect(ClubSetting::current()->name)->toBe('Rotary Club Provisioning');

    $admin = User::where('email', 'premiere.admin@provisioning.test')->firstOrFail();
    expect($admin->name)->toBe('Première Admin');

    Queue::assertPushed(
        SendNewAdminCredentialsMailJob::class,
        fn (SendNewAdminCredentialsMailJob $job) => $job->tenantId === $tenant->id && $job->userId === $admin->id
    );

    @unlink($tenant->sqlite_path);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TenantProvisioningServiceTest`
Expected: FAIL — `TenantProvisioningService` doesn't exist.

- [ ] **Step 3: Write the service (moving the logic out of `TenantController::store` verbatim)**

```php
<?php

namespace App\Services;

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function provision(string $name, string $host, string $adminName, string $adminEmail): Tenant
    {
        $previousTenant = $this->tenantContext->current();

        $directory = database_path('data/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $sqlitePath = $directory.'/'.Str::uuid().'.sqlite';
        touch($sqlitePath);

        $this->tenantContext->use(new Tenant([
            'name' => $name,
            'host' => $host,
            'sqlite_path' => $sqlitePath,
        ]));
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        ClubSetting::current()?->update([
            'name' => $name,
            'tagline' => null,
        ]);

        $tenant = Tenant::create([
            'name' => $name,
            'host' => $host,
            'sqlite_path' => $sqlitePath,
        ]);

        $password = Str::password(16);

        $admin = User::create([
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => $password,
        ]);

        SendNewAdminCredentialsMailJob::dispatch($tenant->id, $admin->id, $password);

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return $tenant;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=TenantProvisioningServiceTest`
Expected: PASS

- [ ] **Step 5: Refactor `TenantController::store` to call the service**

Replace the body of `store()` in `app/Http/Controllers/SuperAdmin/TenantController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
use App\Services\TenantProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function __construct(private readonly TenantProvisioningService $provisioningService) {}

    public function index(): View
    {
        return view('super-admin.tenants.index', [
            'tenants' => \App\Models\Tenant::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $this->provisioningService->provision(
            $request->validated('name'),
            $request->validated('host'),
            $request->validated('admin_name'),
            $request->validated('admin_email'),
        );

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }
}
```

(The `plan_id` handling and offered-`Subscription` creation are added in Task 8 — this step only removes the duplicated provisioning logic.)

- [ ] **Step 6: Run the existing tenant provisioning tests to confirm no regression**

Run: `php artisan test --compact --filter=TenantProvisioning`
Expected: PASS — `TenantProvisioningTest`, `TenantProvisioningLoginTest` (Feature) and `TenantProvisioningMigrationTest` (Tenancy) all still pass unchanged, since `store()`'s externally-visible behavior didn't change.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/TenantProvisioningService.php app/Http/Controllers/SuperAdmin/TenantController.php tests/Feature/Services/TenantProvisioningServiceTest.php
git commit -m "refactor: extract tenant provisioning into TenantProvisioningService"
```

---

## Task 8: Manual tenant creation requires an offered plan

**Files:**
- Modify: `app/Http/Requests/SuperAdmin/StoreTenantRequest.php`
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Modify: `resources/views/super-admin/tenants/create.blade.php`
- Modify: `tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php`
- Modify: `tests/Tenancy/TenantProvisioningMigrationTest.php`
- Test: `tests/Feature/SuperAdmin/TenantProvisioningTest.php` (add a case)

**Interfaces:**
- Consumes: `Plan` (Task 1), `Subscription` (Task 3), `TenantProvisioningService::provision()` (Task 7).
- Produces: `StoreTenantRequest` now requires `plan_id`; `TenantController::store` creates a `source = Subscription::SOURCE_OFFERED` subscription for the new tenant.

- [ ] **Step 1: Update the two existing full-provisioning tests to send `plan_id`**

In `tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php`, change the `post()` call:

```php
    Queue::fake();

    $plan = \App\Models\Plan::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Nouveau',
            'host' => 'nouveau.example.test',
            'admin_name' => 'Première Admin',
            'admin_email' => 'premiere.admin@example.test',
            'plan_id' => $plan->id,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));
```

In `tests/Tenancy/TenantProvisioningMigrationTest.php`, make the same change — add `$plan = \App\Models\Plan::factory()->create();` before the request and `'plan_id' => $plan->id,` to the payload. Note: this test uses `TenancyTestCase`, which migrates its own throwaway `central` SQLite file (see `tests/TenancyTestCase.php`) — the `plans` migration from Task 1 is picked up automatically the same way the `tenants` migration already is, no harness change needed.

- [ ] **Step 2: Write the new failing test in `TenantProvisioningTest.php`**

Add to `tests/Feature/SuperAdmin/TenantProvisioningTest.php`:

```php
use App\Models\Plan;
use App\Models\Subscription;

it('creates an offered subscription atomically with the tenant', function () {
    $plan = Plan::factory()->create(['duration_months' => 3]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Offert',
            'host' => 'offert.example.test',
            'admin_name' => 'Admin Offert',
            'admin_email' => 'admin@offert.test',
            'plan_id' => $plan->id,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'offert.example.test')->firstOrFail();
    $subscription = $tenant->currentSubscription();

    expect($subscription->source)->toBe(Subscription::SOURCE_OFFERED)
        ->and($subscription->amount)->toBe(0)
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);

    @unlink($tenant->sqlite_path);
});

it('rejects tenant creation without a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Sans Plan',
            'host' => 'sansplan.example.test',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@sansplan.test',
        ])->assertSessionHasErrors(['plan_id']);
});
```

- [ ] **Step 3: Run the tests to verify the new ones fail and the updated ones still pass their old assertions minus `plan_id`**

Run: `php artisan test --compact --filter=TenantProvisioning`
Expected: the two updated tests still PASS (they already send valid data, `plan_id` just wasn't required yet); the two new tests FAIL.

- [ ] **Step 4: Add `plan_id` to `StoreTenantRequest`**

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255', 'unique:central.tenants,host'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'plan_id' => ['required', 'integer', 'exists:central.plans,id'],
        ];
    }
}
```

- [ ] **Step 5: Create the offered subscription in `TenantController::store`**

```php
use App\Models\Plan;
use App\Models\Subscription;

// ...

public function store(StoreTenantRequest $request): RedirectResponse
{
    $tenant = $this->provisioningService->provision(
        $request->validated('name'),
        $request->validated('host'),
        $request->validated('admin_name'),
        $request->validated('admin_email'),
    );

    $plan = Plan::findOrFail($request->validated('plan_id'));

    Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'transaction_id' => null,
        'source' => Subscription::SOURCE_OFFERED,
        'amount' => 0,
        'start_date' => now(),
        'end_date' => now()->addMonths($plan->duration_months),
    ]);

    return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
}
```

- [ ] **Step 6: Add the plan select to the create form**

In `resources/views/super-admin/tenants/create.blade.php`, insert before the closing `<p class="text-sm text-muted">` paragraph:

```blade
            <div class="flex flex-col gap-1.5">
                <label for="plan_id" class="text-sm font-semibold">Plan offert</label>
                <select id="plan_id" name="plan_id" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="">— Choisir —</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id') == $plan->id)>{{ $plan->name }} ({{ $plan->duration_months }} mois)</option>
                    @endforeach
                </select>
            </div>
```

And update `TenantController::create()` to pass the plans:

```php
public function create(): View
{
    return view('super-admin.tenants.create', [
        'plans' => \App\Models\Plan::where('is_active', true)->orderBy('duration_months')->get(),
    ]);
}
```

- [ ] **Step 7: Run all the tenant provisioning tests to verify they pass**

Run: `php artisan test --compact --filter=TenantProvisioning`
Expected: PASS (all four: `TenantProvisioningTest`, `TenantProvisioningLoginTest`, `TenantProvisioningMigrationTest`, plus the two new cases in `TenantProvisioningTest`).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SuperAdmin/StoreTenantRequest.php app/Http/Controllers/SuperAdmin/TenantController.php resources/views/super-admin/tenants/create.blade.php tests/Feature/SuperAdmin/TenantProvisioningTest.php tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php tests/Tenancy/TenantProvisioningMigrationTest.php
git commit -m "feat: require an offered plan when a super-admin creates a tenant"
```

---

## Task 9: Super-admin `PlanController` CRUD

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/PlanController.php`
- Create: `app/Http/Requests/SuperAdmin/StorePlanRequest.php`
- Create: `app/Http/Requests/SuperAdmin/UpdatePlanRequest.php`
- Create: `resources/views/super-admin/plans/index.blade.php`
- Create: `resources/views/super-admin/plans/create.blade.php`
- Create: `resources/views/super-admin/plans/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/super-admin.blade.php` (nav link)
- Test: `tests/Feature/SuperAdmin/PlanManagementTest.php`

**Interfaces:**
- Consumes: `Plan` (Task 1).
- Produces: named routes `super-admin.plans.index`, `.create`, `.store`, `.edit`, `.update`, `.toggle-active`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\SuperAdmin;

it('lists plans', function () {
    Plan::factory()->create(['name' => 'Annuel Test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/plans'))
        ->assertOk()
        ->assertSee('Annuel Test');
});

it('creates a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/plans'), [
            'name' => 'Trimestriel',
            'duration_months' => 3,
            'price' => 15000,
        ])->assertRedirect(superAdminUrl('superadmin/plans'));

    expect(Plan::where('name', 'Trimestriel')->exists())->toBeTrue();
});

it('validates required fields when creating a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/plans'), [])
        ->assertSessionHasErrors(['name', 'duration_months', 'price']);
});

it('updates a plan', function () {
    $plan = Plan::factory()->create(['name' => 'Ancien nom']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl("superadmin/plans/{$plan->id}"), [
            'name' => 'Nouveau nom',
            'duration_months' => $plan->duration_months,
            'price' => $plan->price,
        ])->assertRedirect(superAdminUrl('superadmin/plans'));

    expect($plan->refresh()->name)->toBe('Nouveau nom');
});

it('toggles a plan active state', function () {
    $plan = Plan::factory()->create(['is_active' => true]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/plans/{$plan->id}/toggle-active"))
        ->assertRedirect(superAdminUrl('superadmin/plans'));

    expect($plan->refresh()->is_active)->toBeFalse();
});

it('redirects guests to the super-admin login', function () {
    $this->get(superAdminUrl('superadmin/plans'))->assertRedirect(superAdminUrl('superadmin/login'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PlanManagementTest`

- [ ] **Step 3: Write the FormRequests**

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'price' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'price' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StorePlanRequest;
use App\Http\Requests\SuperAdmin\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('super-admin.plans.index', [
            'plans' => Plan::orderBy('duration_months')->get(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.plans.create');
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        Plan::create($request->validated());

        return redirect()->route('super-admin.plans.index')->with('status', 'Plan créé.');
    }

    public function edit(Plan $plan): View
    {
        return view('super-admin.plans.edit', ['plan' => $plan]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->validated());

        return redirect()->route('super-admin.plans.index')->with('status', 'Plan mis à jour.');
    }

    public function toggleActive(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return redirect()->route('super-admin.plans.index');
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the existing `Route::middleware(['auth:super_admin', 'auth.session.guard:super_admin'])->group(...)` block (super-admin authenticated routes), add:

```php
use App\Http\Controllers\SuperAdmin\PlanController;

// ...

Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
Route::patch('plans/{plan}/toggle-active', [PlanController::class, 'toggleActive'])->name('plans.toggle-active');
```

- [ ] **Step 6: Write the views**

`resources/views/super-admin/plans/index.blade.php`:

```blade
<x-layouts.super-admin title="Plans — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Plans</h1>
            <a href="{{ route('super-admin.plans.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un plan
            </a>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Durée</th>
                        <th class="py-2 pr-4 font-semibold">Prix</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $plan->name }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $plan->duration_months }} mois</td>
                            <td class="py-3 pr-4 text-muted">{{ number_format($plan->price, 0, ',', ' ') }} FCFA</td>
                            <td class="py-3 pr-4">{{ $plan->is_active ? 'Actif' : 'Inactif' }}</td>
                            <td class="py-3 pr-4 flex gap-3">
                                <a href="{{ route('super-admin.plans.edit', $plan) }}" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">Modifier</a>
                                <form method="POST" action="{{ route('super-admin.plans.toggle-active', $plan) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        {{ $plan->is_active ? 'Désactiver' : 'Activer' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
```

`resources/views/super-admin/plans/create.blade.php`:

```blade
<x-layouts.super-admin title="Nouveau plan — Super-admin">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Nouveau plan</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.plans.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="duration_months" class="text-sm font-semibold">Durée (mois)</label>
                <input type="number" id="duration_months" name="duration_months" value="{{ old('duration_months') }}" required min="1"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="price" class="text-sm font-semibold">Prix (FCFA)</label>
                <input type="number" id="price" name="price" value="{{ old('price') }}" required min="0"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer le plan
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

`resources/views/super-admin/plans/edit.blade.php` (same form, pre-filled, posting to `super-admin.plans.update`):

```blade
<x-layouts.super-admin title="Modifier le plan — Super-admin">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier le plan</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.plans.update', $plan) }}" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $plan->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="duration_months" class="text-sm font-semibold">Durée (mois)</label>
                <input type="number" id="duration_months" name="duration_months" value="{{ old('duration_months', $plan->duration_months) }}" required min="1"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="price" class="text-sm font-semibold">Prix (FCFA)</label>
                <input type="number" id="price" name="price" value="{{ old('price', $plan->price) }}" required min="0"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 7: Add the nav link**

In `resources/views/components/layouts/super-admin.blade.php`, add a link next to the existing "Clubs" one:

```blade
                    <a href="{{ route('super-admin.plans.index') }}" class="text-navy hover:text-navy-hover">Plans</a>
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=PlanManagementTest`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/PlanController.php app/Http/Requests/SuperAdmin/StorePlanRequest.php app/Http/Requests/SuperAdmin/UpdatePlanRequest.php resources/views/super-admin/plans routes/web.php resources/views/components/layouts/super-admin.blade.php tests/Feature/SuperAdmin/PlanManagementTest.php
git commit -m "feat: let the super-admin manage the plan catalog"
```

---

## Task 10: Super-admin default grace period setting

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/PlatformSettingController.php`
- Create: `app/Http/Requests/SuperAdmin/UpdatePlatformSettingRequest.php`
- Create: `resources/views/super-admin/settings/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/super-admin.blade.php` (nav link)
- Test: `tests/Feature/SuperAdmin/PlatformSettingTest.php`

**Interfaces:**
- Consumes: `PlatformSetting` (Task 4).
- Produces: named routes `super-admin.settings.edit`, `super-admin.settings.update`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\PlatformSetting;
use App\Models\SuperAdmin;

it('shows the current default grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 10]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/settings'))
        ->assertOk()
        ->assertSee('10');
});

it('updates the default grace period', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl('superadmin/settings'), ['default_grace_period_days' => 14])
        ->assertRedirect(superAdminUrl('superadmin/settings'));

    expect(PlatformSetting::current()->default_grace_period_days)->toBe(14);
});

it('validates the grace period is a non-negative integer', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl('superadmin/settings'), ['default_grace_period_days' => -1])
        ->assertSessionHasErrors(['default_grace_period_days']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PlatformSettingTest`

- [ ] **Step 3: Write the FormRequest**

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'default_grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdatePlatformSettingRequest;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlatformSettingController extends Controller
{
    public function edit(): View
    {
        return view('super-admin.settings.edit', [
            'platformSetting' => PlatformSetting::current(),
        ]);
    }

    public function update(UpdatePlatformSettingRequest $request): RedirectResponse
    {
        PlatformSetting::current()->update($request->validated());

        return redirect()->route('super-admin.settings.edit')->with('status', 'Réglages enregistrés.');
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, in the same super-admin authenticated block as Task 9:

```php
use App\Http\Controllers\SuperAdmin\PlatformSettingController;

Route::get('settings', [PlatformSettingController::class, 'edit'])->name('settings.edit');
Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');
```

- [ ] **Step 6: Write the view**

```blade
<x-layouts.super-admin title="Réglages — Super-admin">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Réglages de la plateforme</h1>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.settings.update') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="default_grace_period_days" class="text-sm font-semibold">Délai de grâce par défaut (jours)</label>
                <input type="number" id="default_grace_period_days" name="default_grace_period_days"
                    value="{{ old('default_grace_period_days', $platformSetting->default_grace_period_days) }}" required min="0"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                <p class="text-sm text-muted">Appliqué à tout club sans délai de grâce spécifique (voir la liste des clubs).</p>
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 7: Add the nav link**

In `resources/views/components/layouts/super-admin.blade.php`:

```blade
                    <a href="{{ route('super-admin.settings.edit') }}" class="text-navy hover:text-navy-hover">Réglages</a>
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=PlatformSettingTest`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/PlatformSettingController.php app/Http/Requests/SuperAdmin/UpdatePlatformSettingRequest.php resources/views/super-admin/settings routes/web.php resources/views/components/layouts/super-admin.blade.php tests/Feature/SuperAdmin/PlatformSettingTest.php
git commit -m "feat: let the super-admin edit the default grace period"
```

---

## Task 11: Bulk grace-period override on the tenants list

**Files:**
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Create: `app/Http/Requests/SuperAdmin/UpdateGracePeriodRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/super-admin/tenants/index.blade.php`
- Test: `tests/Feature/SuperAdmin/TenantGracePeriodTest.php`

**Interfaces:**
- Consumes: `Tenant::grace_period_days` (Task 5).
- Produces: `PATCH super-admin/tenants/grace-period`, route name `super-admin.tenants.grace-period`, accepting `tenant_ids: int[]` and nullable `grace_period_days`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('sets a grace period override for one or more selected tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), [
            'tenant_ids' => [$tenantA->id, $tenantB->id],
            'grace_period_days' => 15,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenantA->refresh()->grace_period_days)->toBe(15)
        ->and($tenantB->refresh()->grace_period_days)->toBe(15);
});

it('clears the override back to the platform default when grace_period_days is empty', function () {
    $tenant = Tenant::factory()->create(['grace_period_days' => 20]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), [
            'tenant_ids' => [$tenant->id],
            'grace_period_days' => null,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->grace_period_days)->toBeNull();
});

it('requires at least one tenant', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), ['tenant_ids' => []])
        ->assertSessionHasErrors(['tenant_ids']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TenantGracePeriodTest`

- [ ] **Step 3: Write the FormRequest**

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGracePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['integer', 'exists:central.tenants,id'],
            'grace_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/SuperAdmin/TenantController.php`, add:

```php
use App\Http\Requests\SuperAdmin\UpdateGracePeriodRequest;
use App\Models\Tenant;

public function updateGracePeriod(UpdateGracePeriodRequest $request): RedirectResponse
{
    Tenant::whereIn('id', $request->validated('tenant_ids'))
        ->update(['grace_period_days' => $request->validated('grace_period_days')]);

    return redirect()->route('super-admin.tenants.index')->with('status', 'Délai de grâce mis à jour.');
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, in the super-admin authenticated block, before `tenants/{tenant}`-shaped routes (there are none currently, so placement just needs to be within the same group):

```php
Route::patch('tenants/grace-period', [TenantController::class, 'updateGracePeriod'])->name('tenants.grace-period');
```

- [ ] **Step 6: Add checkboxes and the bulk-edit form to the tenants index**

Replace `resources/views/super-admin/tenants/index.blade.php` with:

```blade
<x-layouts.super-admin title="Clubs — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Clubs</h1>
            <a href="{{ route('super-admin.tenants.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un club
            </a>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.tenants.grace-period') }}" class="mt-6">
            @csrf
            @method('PATCH')
            <div class="mb-3 flex items-center gap-3">
                <input type="number" name="grace_period_days" min="0" placeholder="Jours (vide = défaut plateforme)"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                    Modifier le délai de grâce pour la sélection
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-divider text-muted-strong">
                            <th class="py-2 pr-4"></th>
                            <th class="py-2 pr-4 font-semibold">Nom</th>
                            <th class="py-2 pr-4 font-semibold">Sous-domaine</th>
                            <th class="py-2 pr-4 font-semibold">Délai de grâce</th>
                            <th class="py-2 pr-4 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($tenants as $tenant)
                            <tr>
                                <td class="py-3 pr-4">
                                    <input type="checkbox" name="tenant_ids[]" value="{{ $tenant->id }}">
                                </td>
                                <td class="py-3 pr-4 font-semibold text-navy">{{ $tenant->name }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $tenant->host }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $tenant->grace_period_days ?? 'Défaut plateforme' }}</td>
                                <td class="py-3 pr-4">
                                    <button type="submit" formaction="{{ route('super-admin.impersonate.start', $tenant) }}" formmethod="POST"
                                        class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        Voir en tant que
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</x-layouts.super-admin>
```

Note the "Voir en tant que" button now sits inside the bulk-edit `<form>` (so its checkbox column lines up) but overrides the form's action/method via `formaction`/`formmethod` — a plain HTML feature, no JS needed, and it still needs its own `@csrf` token since it posts to a different route: change that button to a nested mini-form instead to keep the CSRF token correct:

```blade
                                <td class="py-3 pr-4">
                                    <form method="POST" action="{{ route('super-admin.impersonate.start', $tenant) }}">
                                        @csrf
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                            Voir en tant que
                                        </button>
                                    </form>
                                </td>
```

(Nested `<form>` tags are invalid HTML, so this inner form must NOT be nested inside the outer bulk-edit `<form>` — restructure so the checkboxes/bulk-edit form wraps only the checkbox column and the bulk submit button above the table, while the table itself sits outside that form, and each row's "Voir en tant que" mini-form stays exactly as it already is today. Use the `form` attribute on the checkboxes to associate them with the outer bulk form instead of nesting: give the bulk form `id="grace-period-form"` and each checkbox `form="grace-period-form"`.)

Final corrected structure:

```blade
<x-layouts.super-admin title="Clubs — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Clubs</h1>
            <a href="{{ route('super-admin.tenants.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un club
            </a>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        <form id="grace-period-form" method="POST" action="{{ route('super-admin.tenants.grace-period') }}" class="mt-6 mb-3 flex items-center gap-3">
            @csrf
            @method('PATCH')
            <input type="number" name="grace_period_days" min="0" placeholder="Jours (vide = défaut plateforme)"
                class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Modifier le délai de grâce pour la sélection
            </button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4"></th>
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Sous-domaine</th>
                        <th class="py-2 pr-4 font-semibold">Délai de grâce</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td class="py-3 pr-4">
                                <input type="checkbox" name="tenant_ids[]" value="{{ $tenant->id }}" form="grace-period-form">
                            </td>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $tenant->name }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $tenant->host }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $tenant->grace_period_days ?? 'Défaut plateforme' }}</td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="{{ route('super-admin.impersonate.start', $tenant) }}">
                                    @csrf
                                    <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        Voir en tant que
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
```

The `form="grace-period-form"` attribute on each checkbox associates it with the bulk-edit `<form>` even though it lives in the `<table>` outside that form's tags — standard HTML5, no JS required, and it keeps each row's own "Voir en tant que" mini-form valid and unnested.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TenantGracePeriodTest`
Expected: PASS

- [ ] **Step 8: Run the existing tenant listing test to confirm no regression**

Run: `php artisan test --compact --filter=TenantProvisioningTest`
Expected: PASS (`it lists existing tenants` still finds the club name text on the page).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/TenantController.php app/Http/Requests/SuperAdmin/UpdateGracePeriodRequest.php routes/web.php resources/views/super-admin/tenants/index.blade.php tests/Feature/SuperAdmin/TenantGracePeriodTest.php
git commit -m "feat: let the super-admin bulk-edit tenant grace periods"
```

---

## Task 12: `payplus-africa/payplus` dependency, config, and `PayPlusGateway`

**Files:**
- Modify: `composer.json`
- Create: `config/payplus.php`
- Modify: `.env.example`
- Create: `app/Services/PayPlusGateway.php`
- Test: `tests/Feature/Services/PayPlusGatewayTest.php`

**Interfaces:**
- Produces: `PayPlusGateway::initiate(float $amount, string $description, string $phone, string $customerFirstName, string $customerLastName, string $customerEmail, array $customData): array` (`['success' => bool, 'token' => ?string, 'message' => ?string]`); `PayPlusGateway::fetchStatus(string $token): array` (`['success' => bool, 'status' => ?string, 'amount' => ?float, 'custom_data' => array<string, mixed>, 'message' => ?string]`).

- [ ] **Step 1: Add the composer dependency**

```bash
composer require payplus-africa/payplus:dev-main
```

Run: `composer show payplus-africa/payplus`
Expected: package listed, installed under `vendor/payplus-africa/payplus`.

- [ ] **Step 2: Write `config/payplus.php`**

```php
<?php

return [
    'api_key' => env('PAYPLUS_API_KEY', ''),

    'mode' => env('PAYPLUS_MODE', 'test'),

    'token' => env('PAYPLUS_TOKEN', ''),

    'base_url' => env('PAYPLUS_BASE_URL', 'https://app.payplus.africa'),

    'application_name' => env('PAYPLUS_APPLICATION_NAME', ''),

    'application_website_url' => env('PAYPLUS_APPLICATION_WEBSITE_URL', ''),

    'application_cancel_url' => env('PAYPLUS_APPLICATION_CANCEL_URL', ''),

    'application_callback_url' => env('PAYPLUS_APPLICATION_CALLBACK_URL', ''),

    'application_return_url' => env('PAYPLUS_APPLICATION_RETURN_URL', ''),

    'with_redirect' => env('PAYPLUS_WITH_REDIRECT', false),
];
```

`with_redirect` defaults to `false` — this app only uses the "straight" mobile-money push flow (no hosted checkout page), matching the `linkfolio` precedent.

- [ ] **Step 3: Add env placeholders**

In `.env.example`, after the `SUPER_ADMIN_HOST=admin.example.test` line, add:

```
PAYPLUS_API_KEY=
PAYPLUS_MODE=test
PAYPLUS_TOKEN=
PAYPLUS_BASE_URL=https://app.payplus.africa
PAYPLUS_APPLICATION_NAME="${APP_NAME}"
PAYPLUS_APPLICATION_WEBSITE_URL=${APP_URL}
PAYPLUS_APPLICATION_CANCEL_URL=${APP_URL}
PAYPLUS_APPLICATION_CALLBACK_URL=${APP_URL}/payplus/callback
PAYPLUS_APPLICATION_RETURN_URL=${APP_URL}
PAYPLUS_WITH_REDIRECT=false
```

Also copy the same block into the local `.env` (not committed, but needed for `composer.json`'s config discovery not to error at runtime) — run:

```bash
grep -q PAYPLUS_API_KEY .env || cat >> .env <<'EOF'

PAYPLUS_API_KEY=
PAYPLUS_MODE=test
PAYPLUS_TOKEN=
PAYPLUS_BASE_URL=https://app.payplus.africa
PAYPLUS_APPLICATION_NAME="RC Cotonou Ife"
PAYPLUS_APPLICATION_WEBSITE_URL=http://localhost
PAYPLUS_APPLICATION_CANCEL_URL=http://localhost
PAYPLUS_APPLICATION_CALLBACK_URL=http://localhost/payplus/callback
PAYPLUS_APPLICATION_RETURN_URL=http://localhost
PAYPLUS_WITH_REDIRECT=false
EOF
```

- [ ] **Step 4: Write the failing test (the only automatable branch — see Global Constraints)**

```php
<?php

use App\Services\PayPlusGateway;

it('fails fast without calling the network when PayPlus credentials are not configured', function () {
    config(['payplus.api_key' => '', 'payplus.token' => '']);

    $result = app(PayPlusGateway::class)->initiate(
        amount: 5000,
        description: 'Abonnement Mensuel',
        phone: '90000000',
        customerFirstName: 'Admin',
        customerLastName: 'Test',
        customerEmail: 'admin@example.test',
        customData: ['reference' => 'SUB-TEST'],
    );

    expect($result)->toBe(['success' => false, 'message' => 'Configuration PayPlus manquante']);
});

it('reports a failed HTTP call when fetching status', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => \Illuminate\Support\Facades\Http::response(null, 500),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('some-token');

    expect($result['success'])->toBeFalse();
});

it('parses a completed status response, normalizing custom_data', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => \Illuminate\Support\Facades\Http::response([
            'response_code' => '00',
            'status' => 'completed',
            'montant' => 5000,
            'custom_data' => [
                ['keyof_customdata' => 'reference', 'valueof_customdata' => 'SUB-TEST'],
            ],
        ], 200),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('some-token');

    expect($result)->toBe([
        'success' => true,
        'status' => 'completed',
        'amount' => 5000,
        'custom_data' => ['reference' => 'SUB-TEST'],
    ]);
});

it('reports failure when PayPlus responds with a non-00 response code', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/pay/v01/straight/checkout-invoice/confirm*' => \Illuminate\Support\Facades\Http::response([
            'response_code' => '01',
            'response_text' => 'Invoice not found',
        ], 200),
    ]);
    config(['payplus.api_key' => 'key', 'payplus.token' => 'token']);

    $result = app(PayPlusGateway::class)->fetchStatus('bad-token');

    expect($result)->toBe(['success' => false, 'message' => 'Invoice not found']);
});
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PayPlusGatewayTest`
Expected: FAIL — `PayPlusGateway` doesn't exist.

- [ ] **Step 6: Write `PayPlusGateway`**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Payplus\Pay\PayPlus;

class PayPlusGateway
{
    /**
     * @param  array<string, mixed>  $customData
     * @return array{success: bool, token?: string, message?: string}
     */
    public function initiate(
        float $amount,
        string $description,
        string $phone,
        string $customerFirstName,
        string $customerLastName,
        string $customerEmail,
        array $customData,
    ): array {
        if (blank(config('payplus.api_key')) || blank(config('payplus.token'))) {
            return ['success' => false, 'message' => 'Configuration PayPlus manquante'];
        }

        $checkout = (new PayPlus())->init();

        $checkout->addItem($description, 1, $amount, $amount);
        $checkout->setTotalAmount($amount);
        $checkout->setDescription($description);

        foreach ($customData as $key => $value) {
            $checkout->addCustomData($key, $value);
        }

        $checkout->setCustomerNumber($phone);
        $checkout->setCustomerFirstName($customerFirstName);
        $checkout->setCustomerLastName($customerLastName);
        $checkout->setCustomerEmail($customerEmail);
        $checkout->setDevise('xof');
        $checkout->setOtp('');

        $result = $checkout->launchPaiement();

        if (isset($result->token)) {
            return ['success' => true, 'token' => $result->token];
        }

        return ['success' => false, 'message' => $result->message ?? "Erreur lors de l'initialisation du paiement"];
    }

    /**
     * @return array{success: bool, status?: ?string, amount?: ?float, custom_data?: array<string, mixed>, message?: string}
     */
    public function fetchStatus(string $token): array
    {
        if (blank(config('payplus.api_key')) || blank(config('payplus.token'))) {
            return ['success' => false, 'message' => 'Configuration PayPlus manquante'];
        }

        $baseUrl = rtrim((string) config('payplus.base_url', 'https://app.payplus.africa'), '/');
        $url = "{$baseUrl}/pay/v01/straight/checkout-invoice/confirm?invoiceToken={$token}";

        $response = Http::withHeaders([
            'Apikey' => config('payplus.api_key'),
            'Authorization' => 'Bearer '.config('payplus.token'),
        ])->get($url);

        if (! $response->successful()) {
            return ['success' => false, 'message' => 'La requête a échoué. Veuillez réessayer ultérieurement.'];
        }

        $data = $response->json();

        if (($data['response_code'] ?? null) !== '00') {
            return ['success' => false, 'message' => $data['response_text'] ?? 'Erreur de traitement'];
        }

        $customData = [];
        foreach ($data['custom_data'] ?? [] as $item) {
            $customData[$item['keyof_customdata']] = $item['valueof_customdata'];
        }

        return [
            'success' => true,
            'status' => $data['status'] ?? null,
            'amount' => $data['montant'] ?? null,
            'custom_data' => $customData,
        ];
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=PayPlusGatewayTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/payplus.php .env.example app/Services/PayPlusGateway.php tests/Feature/Services/PayPlusGatewayTest.php
git commit -m "feat: add PayPlus mobile-money gateway service"
```

---

## Task 13: `Admin\SubscriptionController@index` — status and renewal page

**Files:**
- Create: `app/Http/Controllers/Admin/SubscriptionController.php`
- Create: `resources/views/admin/subscription/index.blade.php`
- Modify: `routes/web.php` (replace the Task 6 placeholder route)
- Modify: `resources/views/components/layouts/admin.blade.php` (nav link)
- Test: `tests/Feature/Admin/SubscriptionIndexTest.php`

**Interfaces:**
- Consumes: `Tenant::accessState()`/`currentSubscription()` (Task 5), `Plan` (Task 1), `TenantContext` (existing).
- Produces: `GET admin/subscription` → `admin.subscription.index`, rendering current status + active plans + past subscriptions.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

it('shows the current plan, end date, and access state', function () {
    $tenant = app(\App\Services\TenantContext::class)->current();
    $plan = Plan::factory()->create(['name' => 'Mensuel Actuel']);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'end_date' => now()->addDays(20),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.index'))
        ->assertOk()
        ->assertSee('Mensuel Actuel')
        ->assertSee('actif', escape: false);
});

it('lists active plans to renew into and hides inactive ones', function () {
    Plan::factory()->create(['name' => 'Plan Actif', 'is_active' => true]);
    Plan::factory()->create(['name' => 'Plan Inactif', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.index'))
        ->assertOk()
        ->assertSee('Plan Actif')
        ->assertDontSee('Plan Inactif');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SubscriptionIndexTest`
Expected: FAIL — the placeholder route from Task 6 returns `response('ok')`, not the real page.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\TenantContext;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        $tenant = $this->tenantContext->current();

        return view('admin.subscription.index', [
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentSubscription(),
            'accessState' => $tenant->accessState(),
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
            'history' => $tenant->subscriptions()->with('plan')->orderByDesc('end_date')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Replace the Task 6 placeholder route**

In `routes/web.php`, replace:

```php
Route::get('subscription', fn () => response('ok'))->name('subscription.index');
```

with:

```php
use App\Http\Controllers\Admin\SubscriptionController;

Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
```

- [ ] **Step 5: Write the view**

```blade
<x-layouts.admin title="Souscription">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Souscription</h1>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ session('error') }}</div>
        @endif

        @if ($currentSubscription)
            <div class="mt-4 rounded-lg border border-divider p-4">
                <p class="text-sm text-muted">Plan actuel</p>
                <p class="font-semibold text-navy">{{ $currentSubscription->plan->name }}</p>
                <p class="mt-2 text-sm text-muted">Statut : <span class="font-semibold">{{ ['active' => 'actif', 'grace' => 'en délai de grâce', 'blocked' => 'bloqué'][$accessState] }}</span></p>
                <p class="text-sm text-muted">Expire le {{ $currentSubscription->end_date->format('d/m/Y') }}</p>
            </div>
        @else
            <p class="mt-4 text-sm text-muted">Aucune souscription pour le moment.</p>
        @endif

        <h2 class="mt-8 font-display text-lg font-bold text-navy">Renouveler</h2>
        <form method="POST" action="{{ route('admin.subscription.checkout') }}" class="mt-4 flex flex-col gap-4 max-w-[420px]">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="plan_id" class="text-sm font-semibold">Plan</label>
                <select id="plan_id" name="plan_id" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} — {{ number_format($plan->price, 0, ',', ' ') }} FCFA ({{ $plan->duration_months }} mois)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="payment_method" class="text-sm font-semibold">Opérateur</label>
                <select id="payment_method" name="payment_method" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="mtn_momo">MTN Mobile Money</option>
                    <option value="moov_money">Moov Money</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Numéro de téléphone</label>
                <input type="text" id="phone" name="phone" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Payer
            </button>
        </form>

        @if ($history->isNotEmpty())
            <h2 class="mt-8 font-display text-lg font-bold text-navy">Historique</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-divider text-muted-strong">
                            <th class="py-2 pr-4 font-semibold">Plan</th>
                            <th class="py-2 pr-4 font-semibold">Période</th>
                            <th class="py-2 pr-4 font-semibold">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($history as $subscription)
                            <tr>
                                <td class="py-2 pr-4">{{ $subscription->plan->name }}</td>
                                <td class="py-2 pr-4">{{ $subscription->start_date->format('d/m/Y') }} — {{ $subscription->end_date->format('d/m/Y') }}</td>
                                <td class="py-2 pr-4">{{ $subscription->source === 'paid' ? 'Payé' : 'Offert' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.admin>
```

- [ ] **Step 6: Add the nav link**

In `resources/views/components/layouts/admin.blade.php`, add alongside the other nav links (e.g. after "Identité du club"):

```blade
                <a href="{{ route('admin.subscription.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.subscription.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-credit-card w-4 text-center" aria-hidden="true"></i> Souscription
                </a>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SubscriptionIndexTest`
Expected: PASS

- [ ] **Step 8: Run the `CheckTenantSubscriptionTest` suite again to confirm the real page still satisfies its assertions**

Run: `php artisan test --compact --filter=CheckTenantSubscriptionTest`
Expected: PASS (the "lets a blocked admin still reach the subscription page" test now hits the real controller instead of the Task-6 stub).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/SubscriptionController.php resources/views/admin/subscription/index.blade.php routes/web.php resources/views/components/layouts/admin.blade.php tests/Feature/Admin/SubscriptionIndexTest.php
git commit -m "feat: add the tenant subscription status and renewal page"
```

---

## Task 14: `SubscriptionActivationService` — shared idempotent activation

**Files:**
- Create: `app/Services/SubscriptionActivationService.php`
- Test: `tests/Feature/Services/SubscriptionActivationServiceTest.php`

**Interfaces:**
- Consumes: `PayPlusGateway::fetchStatus()` (Task 12), `Transaction`/`Subscription`/`Plan` (Tasks 1-3), `TenantProvisioningService::provision()` (Task 7, used starting Task 19).
- Produces: `SubscriptionActivationService::activateFromToken(string $token): array` (`['success' => bool, 'status' => 'completed'|'pending'|'failed', 'message' => string]`). This is the single method both the poll endpoint (Task 15) and the webhook (Task 16) call.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use App\Services\SubscriptionActivationService;

it('activates a completed payment into a new subscription', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['duration_months' => 3, 'price' => 15000]);
    $transaction = Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-ACTIVATE',
        'amount' => 15000,
        'status' => Transaction::STATUS_PENDING,
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'amount' => 15000,
            'custom_data' => ['reference' => 'SUB-ACTIVATE'],
        ]);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => true, 'status' => 'completed', 'message' => 'Abonnement activé avec succès']);

    $transaction->refresh();
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);

    $subscription = $tenant->currentSubscription();
    expect($subscription->source)->toBe(Subscription::SOURCE_PAID)
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->transaction_id)->toBe($transaction->id)
        ->and($subscription->end_date->diffInDays($subscription->start_date))->toBeGreaterThanOrEqual(89);
});

it('is idempotent when called twice for the same reference (poll + webhook race)', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-RACE',
        'status' => Transaction::STATUS_PENDING,
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->twice()->andReturn([
            'success' => true,
            'status' => 'completed',
            'amount' => 5000,
            'custom_data' => ['reference' => 'SUB-RACE'],
        ]);
    });

    $service = app(SubscriptionActivationService::class);
    $service->activateFromToken('token-a');
    $result = $service->activateFromToken('token-b');

    expect($result)->toBe(['success' => true, 'status' => 'completed', 'message' => 'Abonnement déjà activé']);
    expect(Subscription::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('reports pending without creating a subscription', function () {
    $tenant = Tenant::factory()->create();
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'reference' => 'SUB-PENDING']);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'custom_data' => ['reference' => 'SUB-PENDING'],
        ]);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => true, 'status' => 'pending', 'message' => 'Paiement en attente de confirmation...']);
    expect(Subscription::count())->toBe(0);
});

it('reports failed when the gateway call itself fails', function () {
    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn(['success' => false, 'message' => 'boom']);
    });

    $result = app(SubscriptionActivationService::class)->activateFromToken('some-token');

    expect($result)->toBe(['success' => false, 'status' => 'pending', 'message' => 'Vérification en cours...']);
});

it('stacks the new period onto the current subscription end date when renewing early', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['duration_months' => 1]);
    $currentEnd = now()->addDays(10);
    Subscription::factory()->create(['tenant_id' => $tenant->id, 'end_date' => $currentEnd]);
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-STACK',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-STACK'],
        ]);
    });

    app(SubscriptionActivationService::class)->activateFromToken('some-token');

    $newSubscription = Subscription::where('tenant_id', $tenant->id)->orderByDesc('id')->first();
    expect($newSubscription->start_date->isSameDay($currentEnd))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SubscriptionActivationServiceTest`
Expected: FAIL — the service doesn't exist yet.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class SubscriptionActivationService
{
    public function __construct(
        private readonly PayPlusGateway $gateway,
        private readonly TenantProvisioningService $provisioningService,
    ) {}

    /**
     * @return array{success: bool, status: string, message: string}
     */
    public function activateFromToken(string $token): array
    {
        $status = $this->gateway->fetchStatus($token);

        if (! $status['success']) {
            return ['success' => false, 'status' => 'pending', 'message' => 'Vérification en cours...'];
        }

        return match ($status['status']) {
            'completed' => $this->activate($status),
            'pending' => ['success' => true, 'status' => 'pending', 'message' => 'Paiement en attente de confirmation...'],
            default => ['success' => true, 'status' => 'failed', 'message' => 'Le paiement a échoué. Veuillez réessayer.'],
        };
    }

    /**
     * @param  array{custom_data: array<string, mixed>}  $apiStatus
     * @return array{success: bool, status: string, message: string}
     */
    private function activate(array $apiStatus): array
    {
        $reference = $apiStatus['custom_data']['reference'] ?? null;

        if ($reference === null) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Données de paiement incomplètes'];
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if ($transaction === null) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Transaction introuvable'];
        }

        if ($transaction->status === Transaction::STATUS_COMPLETED) {
            return ['success' => true, 'status' => 'completed', 'message' => 'Abonnement déjà activé'];
        }

        DB::connection('central')->transaction(function () use ($transaction) {
            $transaction->update(['status' => Transaction::STATUS_COMPLETED, 'paid_at' => now()]);

            $tenant = $transaction->tenant_id !== null
                ? Tenant::findOrFail($transaction->tenant_id)
                : $this->provisionFromSelfService($transaction);

            $plan = Plan::findOrFail($transaction->plan_id);
            $current = $tenant->currentSubscription();
            $startDate = ($current !== null && $current->end_date->isFuture()) ? $current->end_date : now();

            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'transaction_id' => $transaction->id,
                'source' => Subscription::SOURCE_PAID,
                'amount' => $transaction->amount,
                'start_date' => $startDate,
                'end_date' => $startDate->copy()->addMonths($plan->duration_months),
            ]);
        });

        return ['success' => true, 'status' => 'completed', 'message' => 'Abonnement activé avec succès'];
    }

    private function provisionFromSelfService(Transaction $transaction): Tenant
    {
        $metadata = $transaction->metadata ?? [];

        $tenant = $this->provisioningService->provision(
            $metadata['club_name'],
            $this->provisioningService->generateUniqueHost($metadata['club_name']),
            $metadata['admin_name'],
            $metadata['admin_email'],
        );

        $transaction->update(['tenant_id' => $tenant->id]);

        return $tenant;
    }
}
```

Note: `TenantProvisioningService::generateUniqueHost()` doesn't exist yet — it's added in Task 18, and `provisionFromSelfService()` is only exercised once Task 19 starts creating tenant-less transactions. Until then this private method is unreachable dead code from every test in this task (they all use `tenant_id`-bearing transactions), which is fine — it's declared now because `activate()` needs to call *some* method here, and splitting it out later would just mean editing this file twice for no benefit.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SubscriptionActivationServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/SubscriptionActivationService.php tests/Feature/Services/SubscriptionActivationServiceTest.php
git commit -m "feat: add idempotent subscription activation shared by poll and webhook"
```

---

## Task 15: `Admin\SubscriptionController@checkout` and pending page

**Files:**
- Modify: `app/Http/Controllers/Admin/SubscriptionController.php`
- Create: `app/Http/Requests/Admin/CheckoutSubscriptionRequest.php`
- Create: `resources/views/admin/subscription/pending.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/SubscriptionCheckoutTest.php`

**Interfaces:**
- Consumes: `PayPlusGateway::initiate()` (Task 12), `Plan`/`Transaction` (Tasks 1-2), `TenantContext` (existing).
- Produces: `POST admin/subscription/checkout` → `admin.subscription.checkout`; `GET admin/subscription/pending` → `admin.subscription.pending`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;

it('creates a pending transaction and redirects to the pending page on success', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => true, 'token' => 'tok-123']);
    });

    $response = $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [
            'plan_id' => $plan->id,
            'payment_method' => 'mtn_momo',
            'phone' => '90000000',
        ]);

    $response->assertRedirect(route('admin.subscription.pending', ['token' => 'tok-123']));

    $transaction = Transaction::where('plan_id', $plan->id)->firstOrFail();
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING)
        ->and($transaction->amount)->toBe($plan->price)
        ->and($transaction->payment_method)->toBe('mtn_momo');
});

it('shows an error and does not create a transaction when the gateway fails to initiate', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => false, 'message' => 'Configuration PayPlus manquante']);
    });

    $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [
            'plan_id' => $plan->id,
            'payment_method' => 'mtn_momo',
            'phone' => '90000000',
        ])->assertRedirect(route('admin.subscription.index'))
        ->assertSessionHas('error', 'Configuration PayPlus manquante');

    expect(Transaction::count())->toBe(0);
});

it('validates the checkout form', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.subscription.checkout'), [])
        ->assertSessionHasErrors(['plan_id', 'payment_method', 'phone']);
});

it('shows the pending page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.pending', ['token' => 'tok-123']))
        ->assertOk()
        ->assertSee('tok-123');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SubscriptionCheckoutTest`

- [ ] **Step 3: Write the FormRequest**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:central.plans,id'],
            'payment_method' => ['required', 'in:mtn_momo,moov_money'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
```

- [ ] **Step 4: Add `checkout` and `pending` to the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckoutSubscriptionRequest;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PayPlusGateway $gateway,
    ) {}

    public function index(): View
    {
        $tenant = $this->tenantContext->current();

        return view('admin.subscription.index', [
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentSubscription(),
            'accessState' => $tenant->accessState(),
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
            'history' => $tenant->subscriptions()->with('plan')->orderByDesc('end_date')->get(),
        ]);
    }

    public function checkout(CheckoutSubscriptionRequest $request): RedirectResponse
    {
        $tenant = $this->tenantContext->current();
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $reference = 'SUB-'.strtoupper(Str::random(12));

        $result = $this->gateway->initiate(
            amount: $plan->price,
            description: "Abonnement {$plan->name}",
            phone: $request->validated('phone'),
            customerFirstName: $tenant->name,
            customerLastName: $tenant->name,
            customerEmail: '',
            customData: ['reference' => $reference],
        );

        if (! $result['success']) {
            return redirect()->route('admin.subscription.index')->with('error', $result['message']);
        }

        Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'reference' => $reference,
            'amount' => $plan->price,
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => $request->validated('payment_method'),
            'payment_token' => $result['token'],
        ]);

        return redirect()->route('admin.subscription.pending', ['token' => $result['token']]);
    }

    public function pending(): View
    {
        return view('admin.subscription.pending', [
            'token' => request()->query('token'),
        ]);
    }
}
```

- [ ] **Step 5: Write the pending view**

```blade
<x-layouts.admin title="Paiement en cours">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Paiement en cours</h1>
        <p class="mt-3 text-sm text-muted">
            Confirmez le paiement mobile money sur votre téléphone. Cette page se mettra à jour automatiquement.
        </p>
        <p class="mt-4 text-xs text-muted" id="status-message">Vérification en cours...</p>
    </div>

    <script>
        (function () {
            const token = @json($token);
            const statusUrl = @json(route('admin.subscription.status'));
            const statusMessage = document.getElementById('status-message');

            function poll() {
                fetch(`${statusUrl}?token=${encodeURIComponent(token)}`)
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.status === 'completed') {
                            window.location.href = @json(route('admin.subscription.index'));
                            return;
                        }
                        if (data.status === 'failed') {
                            statusMessage.textContent = data.message;
                            return;
                        }
                        statusMessage.textContent = data.message;
                        setTimeout(poll, 3000);
                    });
            }

            poll();
        })();
    </script>
</x-layouts.admin>
```

(The `admin.subscription.status` polling endpoint is added in Task 16 — this view references it now so Task 16 only needs to add the route/controller method, not touch this file again.)

- [ ] **Step 6: Add the routes**

In `routes/web.php`, inside the same non-`CheckTenantSubscription` subscription sub-group as `subscription.index` (Task 6/13):

```php
Route::post('subscription/checkout', [SubscriptionController::class, 'checkout'])->name('subscription.checkout');
Route::get('subscription/pending', [SubscriptionController::class, 'pending'])->name('subscription.pending');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SubscriptionCheckoutTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/SubscriptionController.php app/Http/Requests/Admin/CheckoutSubscriptionRequest.php resources/views/admin/subscription/pending.blade.php routes/web.php tests/Feature/Admin/SubscriptionCheckoutTest.php
git commit -m "feat: let a tenant admin start a PayPlus subscription checkout"
```

---

## Task 16: `Admin\SubscriptionController@checkPaymentStatus` — poll endpoint

**Files:**
- Modify: `app/Http/Controllers/Admin/SubscriptionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/SubscriptionStatusPollTest.php`

**Interfaces:**
- Consumes: `SubscriptionActivationService::activateFromToken()` (Task 14).
- Produces: `GET admin/subscription/status?token=...` → `admin.subscription.status`, JSON `{success, status, message}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;
use App\Services\TenantContext;

it('activates the subscription and returns completed when the gateway confirms', function () {
    $tenant = app(TenantContext::class)->current();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-POLL',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-POLL'],
        ]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.subscription.status', ['token' => 'tok-poll']))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'completed']);

    expect($tenant->currentSubscription())->not->toBeNull();
});

it('returns pending while the gateway has not confirmed yet', function () {
    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn(['success' => false]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.subscription.status', ['token' => 'tok-poll-2']))
        ->assertOk()
        ->assertJson(['success' => false, 'status' => 'pending']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SubscriptionStatusPollTest`

- [ ] **Step 3: Add `checkPaymentStatus` to the controller**

```php
use App\Services\SubscriptionActivationService;
use Illuminate\Http\JsonResponse;

public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly PayPlusGateway $gateway,
    private readonly SubscriptionActivationService $activationService,
) {}

// ...

public function checkPaymentStatus(): JsonResponse
{
    $result = $this->activationService->activateFromToken(request()->query('token'));

    return response()->json($result);
}
```

- [ ] **Step 4: Add the route**

```php
Route::get('subscription/status', [SubscriptionController::class, 'checkPaymentStatus'])->name('subscription.status');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SubscriptionStatusPollTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/SubscriptionController.php routes/web.php tests/Feature/Admin/SubscriptionStatusPollTest.php
git commit -m "feat: poll PayPlus payment status from the pending page"
```

---

## Task 17: `PayPlusCallbackController` — global webhook

**Files:**
- Create: `app/Http/Controllers/PayPlusCallbackController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PayPlusCallbackTest.php`

**Interfaces:**
- Consumes: `SubscriptionActivationService::activateFromToken()` (Task 14).
- Produces: `POST /payplus/callback` → `payplus.callback`, outside every `ResolveTenant`/`CheckTenantSubscription` group.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayPlusGateway;

it('activates the subscription on a successful callback', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    Transaction::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'reference' => 'SUB-WEBHOOK',
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-WEBHOOK'],
        ]);
    });

    $this->postJson('/payplus/callback', ['token' => 'tok-webhook', 'response_code' => '00'])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    expect($tenant->currentSubscription())->not->toBeNull();
});

it('rejects a callback with a non-00 response code without calling the gateway', function () {
    $this->postJson('/payplus/callback', ['token' => 'tok-bad', 'response_code' => '99'])
        ->assertStatus(400)
        ->assertJson(['status' => 'error']);
});

it('rejects a callback without a token', function () {
    $this->postJson('/payplus/callback', ['response_code' => '00'])
        ->assertStatus(400);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PayPlusCallbackTest`

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPlusCallbackController extends Controller
{
    public function __construct(private readonly SubscriptionActivationService $activationService) {}

    public function handle(Request $request): JsonResponse
    {
        $token = $request->input('token');

        if (blank($token)) {
            return response()->json(['status' => 'error', 'message' => 'Token manquant'], 400);
        }

        if ($request->input('response_code') !== '00') {
            return response()->json(['status' => 'error'], 400);
        }

        $result = $this->activationService->activateFromToken($token);

        if (! $result['success']) {
            return response()->json(['status' => 'error'], 400);
        }

        return response()->json(['status' => $result['status'] === 'completed' ? 'success' : $result['status']]);
    }
}
```

- [ ] **Step 4: Add the route, outside `ResolveTenant`**

In `routes/web.php`, at the very end of the file, outside every existing `Route::domain(...)`/`Route::middleware(ResolveTenant::class)` group:

```php
use App\Http\Controllers\PayPlusCallbackController;

Route::post('/payplus/callback', [PayPlusCallbackController::class, 'handle'])->name('payplus.callback');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=PayPlusCallbackTest`
Expected: PASS

- [ ] **Step 6: Update `PAYPLUS_APPLICATION_CALLBACK_URL` note**

No code change — `config/payplus.php`'s `application_callback_url` (Task 12) should point at this route in production (`{APP_URL}/payplus/callback`, already set that way in `.env.example`).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PayPlusCallbackController.php routes/web.php tests/Feature/PayPlusCallbackTest.php
git commit -m "feat: add the global PayPlus webhook callback"
```

---

## Task 18: `tenancy.base_domain` and unique host generation

**Files:**
- Modify: `config/tenancy.php`
- Modify: `.env.example`
- Modify: `app/Services/TenantProvisioningService.php`
- Test: `tests/Feature/Services/TenantProvisioningServiceTest.php` (add cases)

**Interfaces:**
- Produces: `config('tenancy.base_domain')`; `TenantProvisioningService::generateUniqueHost(string $clubName): string`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Services/TenantProvisioningServiceTest.php`:

```php
use App\Models\Tenant;
use App\Services\TenantProvisioningService;

it('slugs the club name against the configured base domain', function () {
    config(['tenancy.base_domain' => 'example.test']);

    $host = app(TenantProvisioningService::class)->generateUniqueHost('Rotary Club Nouveau');

    expect($host)->toBe('rotary-club-nouveau.example.test');
});

it('de-duplicates the slug when the host already exists', function () {
    config(['tenancy.base_domain' => 'example.test']);
    Tenant::factory()->create(['host' => 'rotary-club-doublon.example.test']);

    $host = app(TenantProvisioningService::class)->generateUniqueHost('Rotary Club Doublon');

    expect($host)->toBe('rotary-club-doublon-2.example.test');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TenantProvisioningServiceTest`

- [ ] **Step 3: Add `base_domain` to `config/tenancy.php`**

```php
<?php

return [
    'super_admin_host' => env('SUPER_ADMIN_HOST', 'admin.example.test'),

    'base_domain' => env('TENANT_BASE_DOMAIN', 'example.test'),
];
```

- [ ] **Step 4: Add the env placeholder**

In `.env.example`, next to `SUPER_ADMIN_HOST`:

```
TENANT_BASE_DOMAIN=example.test
```

- [ ] **Step 5: Add `generateUniqueHost()` to `TenantProvisioningService`**

```php
use App\Models\Tenant;
use Illuminate\Support\Str;

public function generateUniqueHost(string $clubName): string
{
    $baseSlug = Str::slug($clubName);
    $baseDomain = config('tenancy.base_domain');

    $host = "{$baseSlug}.{$baseDomain}";
    $suffix = 2;

    while (Tenant::where('host', $host)->exists()) {
        $host = "{$baseSlug}-{$suffix}.{$baseDomain}";
        $suffix++;
    }

    return $host;
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TenantProvisioningServiceTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/tenancy.php .env.example app/Services/TenantProvisioningService.php tests/Feature/Services/TenantProvisioningServiceTest.php
git commit -m "feat: generate a unique tenant host from a club name"
```

---

## Task 19: `SignupController` — public self-service signup

**Files:**
- Create: `app/Http/Controllers/SignupController.php`
- Create: `app/Http/Requests/SignupRequest.php`
- Create: `resources/views/signup/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SignupTest.php`

**Interfaces:**
- Consumes: `PayPlusGateway::initiate()` (Task 12), `Plan`/`Transaction` (Tasks 1-2).
- Produces: `GET /inscription` → `signup.show`; `POST /inscription` → `signup.store`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;

it('shows the signup form with active plans', function () {
    Plan::factory()->create(['name' => 'Plan Signup', 'is_active' => true]);

    $this->get(superAdminUrl('inscription'))
        ->assertOk()
        ->assertSee('Plan Signup');
});

it('creates a tenant-less pending transaction and redirects to the pending page on success', function () {
    $plan = Plan::factory()->create();

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('initiate')->once()->andReturn(['success' => true, 'token' => 'tok-signup']);
    });

    $response = $this->post(superAdminUrl('inscription'), [
        'club_name' => 'Rotary Club Signup',
        'admin_name' => 'Admin Signup',
        'admin_email' => 'admin@signup.test',
        'plan_id' => $plan->id,
        'payment_method' => 'mtn_momo',
        'phone' => '90000000',
    ]);

    $response->assertRedirect();

    $transaction = Transaction::whereNull('tenant_id')->firstOrFail();
    expect($transaction->metadata['club_name'])->toBe('Rotary Club Signup')
        ->and($transaction->metadata['admin_name'])->toBe('Admin Signup')
        ->and($transaction->metadata['admin_email'])->toBe('admin@signup.test')
        ->and($transaction->plan_id)->toBe($plan->id);
});

it('validates the signup form', function () {
    $this->post(superAdminUrl('inscription'), [])
        ->assertSessionHasErrors(['club_name', 'admin_name', 'admin_email', 'plan_id', 'payment_method', 'phone']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SignupTest`

- [ ] **Step 3: Write the FormRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'club_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'plan_id' => ['required', 'integer', 'exists:central.plans,id'],
            'payment_method' => ['required', 'in:mtn_momo,moov_money'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignupRequest;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\PayPlusGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SignupController extends Controller
{
    public function __construct(private readonly PayPlusGateway $gateway) {}

    public function show(): View
    {
        return view('signup.show', [
            'plans' => Plan::where('is_active', true)->orderBy('duration_months')->get(),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $reference = 'SUB-'.strtoupper(Str::random(12));

        $result = $this->gateway->initiate(
            amount: $plan->price,
            description: "Abonnement {$plan->name}",
            phone: $request->validated('phone'),
            customerFirstName: $request->validated('admin_name'),
            customerLastName: $request->validated('admin_name'),
            customerEmail: $request->validated('admin_email'),
            customData: ['reference' => $reference],
        );

        if (! $result['success']) {
            return redirect()->route('signup.show')->with('error', $result['message']);
        }

        Transaction::create([
            'tenant_id' => null,
            'plan_id' => $plan->id,
            'reference' => $reference,
            'amount' => $plan->price,
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => $request->validated('payment_method'),
            'payment_token' => $result['token'],
            'metadata' => [
                'club_name' => $request->validated('club_name'),
                'admin_name' => $request->validated('admin_name'),
                'admin_email' => $request->validated('admin_email'),
            ],
        ]);

        return redirect()->route('signup.pending', ['token' => $result['token']]);
    }

    public function pending(): View
    {
        return view('signup.pending', ['token' => request()->query('token')]);
    }

    public function checkPaymentStatus(\App\Services\SubscriptionActivationService $activationService): \Illuminate\Http\JsonResponse
    {
        return response()->json($activationService->activateFromToken(request()->query('token')));
    }
}
```

- [ ] **Step 5: Write the signup views**

`resources/views/signup/show.blade.php`:

```blade
<x-layouts.super-admin title="Inscription">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Inscrire mon club</h1>

        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('signup.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="club_name" class="text-sm font-semibold">Nom du club</label>
                <input type="text" id="club_name" name="club_name" value="{{ old('club_name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_name" class="text-sm font-semibold">Votre nom</label>
                <input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_email" class="text-sm font-semibold">Votre email</label>
                <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="plan_id" class="text-sm font-semibold">Plan</label>
                <select id="plan_id" name="plan_id" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} — {{ number_format($plan->price, 0, ',', ' ') }} FCFA ({{ $plan->duration_months }} mois)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="payment_method" class="text-sm font-semibold">Opérateur</label>
                <select id="payment_method" name="payment_method" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="mtn_momo">MTN Mobile Money</option>
                    <option value="moov_money">Moov Money</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Numéro de téléphone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Payer et créer mon club
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

`resources/views/signup/pending.blade.php` (same polling pattern as `admin/subscription/pending.blade.php` in Task 15, pointed at the signup status endpoint instead):

```blade
<x-layouts.super-admin title="Paiement en cours">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Paiement en cours</h1>
        <p class="mt-3 text-sm text-muted">
            Confirmez le paiement mobile money sur votre téléphone. Votre club sera créé automatiquement une fois le paiement confirmé, et vos identifiants vous seront envoyés par email.
        </p>
        <p class="mt-4 text-xs text-muted" id="status-message">Vérification en cours...</p>
    </div>

    <script>
        (function () {
            const token = @json($token);
            const statusUrl = @json(route('signup.status'));
            const statusMessage = document.getElementById('status-message');

            function poll() {
                fetch(`${statusUrl}?token=${encodeURIComponent(token)}`)
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.status === 'completed') {
                            statusMessage.textContent = 'Club créé ! Vérifiez votre email pour vos identifiants.';
                            return;
                        }
                        if (data.status === 'failed') {
                            statusMessage.textContent = data.message;
                            return;
                        }
                        statusMessage.textContent = data.message;
                        setTimeout(poll, 3000);
                    });
            }

            poll();
        })();
    </script>
</x-layouts.super-admin>
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, inside the existing `Route::domain(config('tenancy.super_admin_host'))->group(function () { ... })` block, alongside the welcome route (public, unauthenticated):

```php
use App\Http\Controllers\SignupController;

Route::get('inscription', [SignupController::class, 'show'])->name('signup.show');
Route::post('inscription', [SignupController::class, 'store'])->name('signup.store');
Route::get('inscription/pending', [SignupController::class, 'pending'])->name('signup.pending');
Route::get('inscription/status', [SignupController::class, 'checkPaymentStatus'])->name('signup.status');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SignupTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SignupController.php app/Http/Requests/SignupRequest.php resources/views/signup routes/web.php tests/Feature/SignupTest.php
git commit -m "feat: add public self-service club signup with PayPlus checkout"
```

---

## Task 20: Self-service provisioning end-to-end

**Files:**
- Test: `tests/Feature/SignupProvisioningTest.php`

**Interfaces:**
- Consumes: `SubscriptionActivationService::activateFromToken()` (Task 14, including its `provisionFromSelfService()` branch), `TenantProvisioningService::generateUniqueHost()` (Task 18).
- Produces: nothing new — this task is pure verification that the pieces built in Tasks 14, 18, and 19 correctly compose. If it fails, the bug is in `SubscriptionActivationService::provisionFromSelfService()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('provisions a tenant, admin, and paid subscription from a confirmed self-service payment', function () {
    Queue::fake();
    config(['tenancy.base_domain' => 'example.test']);

    $plan = Plan::factory()->create(['duration_months' => 1, 'price' => 5000]);
    Transaction::factory()->selfService()->create([
        'plan_id' => $plan->id,
        'reference' => 'SUB-E2E',
        'amount' => 5000,
        'metadata' => [
            'club_name' => 'Rotary Club E2E',
            'admin_name' => 'Admin E2E',
            'admin_email' => 'admin@e2e.test',
        ],
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-E2E'],
        ]);
    });

    $this->postJson('/payplus/callback', ['token' => 'tok-e2e', 'response_code' => '00'])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    $tenant = Tenant::where('host', 'rotary-club-e2e.example.test')->firstOrFail();
    expect($tenant->name)->toBe('Rotary Club E2E')
        ->and(file_exists($tenant->sqlite_path))->toBeTrue();

    $subscription = $tenant->currentSubscription();
    expect($subscription->source)->toBe(Subscription::SOURCE_PAID)
        ->and($subscription->amount)->toBe(5000)
        ->and($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');
    expect(Schema::hasTable('club_settings'))->toBeTrue();
    expect(ClubSetting::current()->name)->toBe('Rotary Club E2E');

    $admin = User::where('email', 'admin@e2e.test')->firstOrFail();
    Queue::assertPushed(
        SendNewAdminCredentialsMailJob::class,
        fn (SendNewAdminCredentialsMailJob $job) => $job->tenantId === $tenant->id && $job->userId === $admin->id
    );

    @unlink($tenant->sqlite_path);
});
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --compact --filter=SignupProvisioningTest`
Expected: PASS immediately if Tasks 14/18/19 were implemented as specified — this test exists to catch integration mistakes between them (e.g. a typo in `$metadata['club_name']` key naming). If it fails, fix `SubscriptionActivationService::provisionFromSelfService()` (Task 14, Step 3) rather than adding new code — the pieces already exist.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/SignupProvisioningTest.php
git commit -m "test: verify self-service signup provisions a tenant end-to-end"
```

---

## Task 21: Welcome page CTA

**Files:**
- Modify: `resources/views/super-admin/welcome.blade.php`
- Test: `tests/Feature/SuperAdmin/WelcomePageTest.php` (add a case, or create if it doesn't already exist)

**Interfaces:**
- Consumes: `signup.show` route (Task 19).
- Produces: nothing new — a link.

- [ ] **Step 1: Check for an existing welcome page test**

Run: `grep -rl "super-admin.welcome\|WelcomeController" tests/`

If a test file exists (e.g. `tests/Feature/SuperAdmin/WelcomeTest.php`), add the new case there instead of creating `WelcomePageTest.php`; adjust the file path in Steps 2-4 accordingly.

- [ ] **Step 2: Write the failing test**

```php
<?php

it('links to the self-service signup page', function () {
    $this->get(superAdminUrl('/'))
        ->assertOk()
        ->assertSee(route('signup.show'), escape: false);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=WelcomePageTest`

- [ ] **Step 4: Add the CTA link**

In `resources/views/super-admin/welcome.blade.php`, after the existing "Se connecter" link:

```blade
        <a href="{{ route('signup.show') }}"
            class="mt-3 block cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
            Inscrire mon club
        </a>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=WelcomePageTest`
Expected: PASS

- [ ] **Step 6: Run the full suite one final time**

Run: `php artisan test --compact`
Expected: PASS — every test across all 21 tasks green together.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/super-admin/welcome.blade.php tests/Feature/SuperAdmin/WelcomePageTest.php
git commit -m "feat: link the welcome page to self-service club signup"
```

---

## Self-Review Notes

- **Spec coverage:** §1 data model → Tasks 1-5; §2 payment/PayPlus reuse → Tasks 12, 14-17 (with the composer-package-vs-testability deviation resolved by user decision to keep the package, accepting the `initiate()` testing gap documented in Global Constraints); §3 access gating → Task 6; §4 super-admin management (plans, grace period, manual creation, bulk override) → Tasks 8-11; §5 self-service signup → Tasks 18-20; §6 testing strategy → covered throughout, `Http::fake()` used for `fetchStatus()`/webhook paths, `$this->mock(PayPlusGateway::class)` used for `initiate()`-dependent paths.
- **Placeholder scan:** no TBD/TODO markers; every step has runnable code or an exact command.
- **Type consistency:** `Tenant::ACCESS_ACTIVE|ACCESS_GRACE|ACCESS_BLOCKED` (Task 5) used identically in Tasks 6, 13; `Subscription::SOURCE_PAID|SOURCE_OFFERED` (Task 3) used identically in Tasks 8, 14; `PayPlusGateway::initiate()`/`fetchStatus()` signatures (Task 12) match every call site in Tasks 14-16, 19; `SubscriptionActivationService::activateFromToken()` (Task 14) matches its callers in Tasks 16, 17, 20; `TenantProvisioningService::provision()`/`generateUniqueHost()` (Tasks 7, 18) match their call sites in Tasks 8, 14.
