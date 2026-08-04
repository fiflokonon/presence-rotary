# Tenant subscriptions — spec

Date: 2026-08-04

## Context

The app already serves multiple clubs (tenants), each isolated in its own
SQLite database and reachable on its own subdomain, provisioned by a
super-admin panel (`docs/superpowers/specs/2026-07-23-multi-tenant-clubs-design.md`).
There is currently no notion of billing: any tenant the super-admin
creates has permanent, unconditional access to the service.

This adds a subscription system that gates a tenant's access to the
service — paid online via PayPlus Africa (mobile money), the same
provider and integration pattern already proven in production in the
`linkfolio` project (`app/Http/Controllers/SubscriptionController.php`
there), reused here rather than reinvented.

Key facts gathered during investigation:

- `linkfolio` uses the `payplus-africa/payplus` composer package in its
  **"straight" (no-redirect) mode**: `PayPlus::init()` →
  `addItem()`/`setTotalAmount()`/`setCustomerNumber()`/`launchPaiement()`
  → returns a `token`. Confirmation is dual-path — the frontend polls a
  status endpoint and PayPlus also POSTs a webhook — both calling the
  same `fetchTransactionStatus($token)` (a raw `Http::get()` to
  `{base_url}/pay/v01/straight/checkout-invoice/confirm?invoiceToken={token}`,
  with `Apikey`/`Authorization` headers from `config/payplus.php`).
  Scoped tenant/plan/reference are round-tripped through `custom_data`
  sent at checkout time. Idempotency is handled by checking for an
  existing `completed` `Transaction` with the same `reference` before
  creating a subscription — both the poll and the webhook can safely
  race.
- `linkfolio`'s `Subscription` model also carries promo codes, refunds,
  and cashback — none of that is needed here and is intentionally left
  out.
- In this app, `Tenant`, `User`, `ClubSetting`, etc. all live on the
  `sqlite` connection, whose underlying file is swapped per-request by
  `TenantContext` based on the resolved `Tenant`. Only `Tenant` and
  `SuperAdmin` live on the `central` connection, which is not swapped and
  is reachable regardless of which tenant (if any) is currently active.
- Manual tenant creation (`TenantController::store`) provisions the
  SQLite file, migrates it, creates the admin `User`, and queues the
  credentials email — in that specific order (migrate before inserting
  the `tenants` row, so a failed migration never orphans a registry
  entry).
- There is no wildcard DNS/TLS: every subdomain is still onboarded
  manually (DNS `A` record, `certbot --expand`, Apache vhost) — called
  out as out of scope in the multi-tenant spec, "revisit once the number
  of clubs makes manual onboarding painful."

## Goal

Give every tenant a subscription (a paid plan, or one offered for free by
the super-admin) that determines whether it can use the service, let a
club's own admin renew online via PayPlus, let a prospective club sign up
and pay for itself without the super-admin's involvement, and let the
super-admin manage the plan catalog and grace periods.

## Design

### 1. Data model (all on the `central` connection)

- **`plans`**: `id`, `name`, `duration_months`, `price` (FCFA),
  `is_active`, timestamps. The billing-tier catalog, managed by the
  super-admin (same shape as `titles`/`positions`: index, create, store,
  edit, update, toggle-active — no destroy, since a plan referenced by
  historical subscriptions must stay resolvable).
- **`transactions`**: `id`, `tenant_id` (nullable — null until a
  self-service signup is provisioned), `plan_id`, `reference` (unique),
  `amount`, `status` (`pending`/`completed`/`failed`), `payment_method`
  (`mtn_momo`/`moov_money`), `payment_token`, `paid_at`, `metadata`
  (json — for self-service signups, carries the intended club name/admin
  name/admin email/host since no tenant exists yet), timestamps. One row
  per payment attempt; the source of truth for idempotency.
- **`subscriptions`**: `id`, `tenant_id`, `plan_id`, `transaction_id`
  (nullable — null when `source = offered`), `source`
  (`paid`/`offered`), `amount`, `start_date`, `end_date`, timestamps. No
  `status` column — access state is always computed from dates (§3), not
  stored. One row per billing period granted; renewing creates a new row
  rather than mutating the old one, so history is a plain query.
- **`tenants`** gains `grace_period_days` (nullable int; null = use the
  platform default).
- **`platform_settings`**: a central singleton, same pattern as
  `ClubSetting::current()`, holding `default_grace_period_days` (seeded
  to 7), editable by the super-admin.

Every tenant gets its first `Subscription` row at creation time — manual
creation requires the super-admin to pick a plan to offer (§4); a
self-service signup creates one from the completed transaction (§5). A
tenant with zero subscription rows is treated as `blocked` defensively,
but shouldn't occur in normal operation.

### 2. Payment: reusing the PayPlus integration from `linkfolio`

Same package (`payplus-africa/payplus`, **new composer dependency —
flagging per project convention that dependency changes need approval**)
and the same "straight" mobile-money push flow described above.

New `App\Services\PayPlusGateway` (extracted from the inline
`processPayPlusPayment`/`fetchTransactionStatus` pair in `linkfolio`'s
controller, since two callers need it here — the tenant-admin checkout
and the self-service signup checkout):
- `initiate(amount, description, phone, customerName, customerEmail, array $customData): array` —
  wraps `PayPlus::init()->addItem()->setTotalAmount()->setCustomerNumber()->launchPaiement()`,
  returns `['success' => bool, 'token' => ?string, 'message' => ?string]`.
- `fetchStatus(string $token): array` — the raw `Http::get()` call with
  `Apikey`/`Authorization` headers, returns
  `['success' => bool, 'status' => ?string, 'amount' => ?float, 'custom_data' => ?array]`.

Because `Plan`/`Transaction`/`Subscription` all live on the `central`
connection, **nothing in this flow needs `TenantContext::use()`** — the
webhook route doesn't need to know which tenant's SQLite is "current" at
all, sidestepping the exact pitfall the queued mail jobs had in the
multi-tenant spec (§4 there).

New `App\Http\Controllers\Admin\SubscriptionController` (tenant-scoped —
reachable by both a club's own `User` and an impersonating
`SuperAdmin`):
- `index` — current plan, end date, access state (active/grace/blocked),
  the active plan catalog to renew into, a payment-method + phone form,
  and a table of the tenant's past subscriptions/transactions. No
  separate history route — folded into this page.
- `checkout` — validates plan + payment method + phone, creates a
  `pending` `Transaction` (`tenant_id` = current tenant), calls
  `PayPlusGateway::initiate()` with `custom_data = ['tenant_id', 'plan_id', 'reference']`,
  redirects to the pending/polling page on success.
- `paymentPending` / `checkPaymentStatus` (polled) — mirrors
  `linkfolio`'s pending page + JS polling loop.
- A single global webhook, `POST /payplus/callback` — **outside**
  `ResolveTenant` entirely (PayPlus doesn't know about tenant hosts;
  there is one callback URL, shared across every tenant and the
  self-service flow). Resolves what to do purely from
  `custom_data.tenant_id` (existing tenant → activate its renewal) or
  its absence (self-service signup → provision, §5).
- Both the poll and the webhook call the same private
  `createAndActivateSubscription($token, $apiStatus)`, guarded by the
  unique `transactions.reference`, exactly like `linkfolio`.

### 3. Access gating

`Tenant` gains a computed method, `accessState(): 'active'|'grace'|'blocked'`,
derived from its latest `Subscription.end_date` (by `end_date`, not
creation order) plus `grace_period_days ?? PlatformSetting::current()->default_grace_period_days`:
- `end_date > now()` → `active`.
- `now()` between `end_date` and `end_date + grace_period_days` → `grace`.
- otherwise → `blocked`.

New `CheckTenantSubscription` middleware, placed after `ResolveTenant`:
- **Public check-in group**: `blocked` → renders a minimal "service
  indisponible, contactez l'administrateur du club" view — still pulls
  `ClubSetting::current()` for branding, no billing details shown to
  guests. `grace` → no change; guests aren't the club's billing problem.
- **Authenticated `admin.*` group**: `blocked` → redirected to
  `admin.subscription.index`. The subscription routes (`index`,
  `checkout`, `paymentPending`, `checkPaymentStatus`) plus login/logout
  are excluded from this middleware, so a blocked admin can always reach
  the page that lets them pay. `grace` → a banner in the admin layout
  ("souscription expirée depuis le {date}, renouvelez avant le
  {grace end date}"), everything else stays usable.
- **Bypassed entirely for an authenticated `super_admin`**, including
  while impersonating — the super-admin can always open a blocked club's
  full admin panel to help it or pay on its behalf via the same
  subscription page.

Route structure (extends the existing `ResolveTenant` group in
`routes/web.php`):

```
Route::middleware(ResolveTenant::class)->group(function () {
    Route::middleware(CheckTenantSubscription::class)->group(function () {
        // public check-in routes (unchanged)
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('guest')->group(fn () => /* login (unchanged) */);

        Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
            Route::post('logout', ...);
            Route::get('subscription', ...)->name('subscription.index');
            Route::post('subscription/checkout', ...)->name('subscription.checkout');
            Route::get('subscription/pending', ...)->name('subscription.pending');
            Route::get('subscription/status', ...)->name('subscription.status');

            Route::middleware(CheckTenantSubscription::class)->group(function () {
                // every other existing admin.* route (unchanged)
            });
        });
    });
});

Route::post('/payplus/callback', [PayPlusCallbackController::class, 'handle'])
    ->name('payplus.callback'); // outside ResolveTenant
```

### 4. Super-admin management

- **Plans**: `SuperAdmin\PlanController` — index/create/store/edit/update/toggle-active,
  same shape as `TitleController`.
- **Grace period**: editable on a new super-admin settings page
  (`PlatformSetting`, global default) and per-tenant from the tenants
  index — checkboxes + a "Modifier le délai de grâce" bulk action
  (`PATCH super-admin/tenants/grace-period` with `tenant_ids[]` +
  nullable `grace_period_days`; empty clears the override back to the
  platform default). The same endpoint handles editing a single tenant.
- **Manual tenant creation**: `StoreTenantRequest` gains a required
  `plan_id` — the super-admin picks which plan to *offer* for free when
  provisioning a club by hand, creating an initial `source = offered`
  `Subscription` atomically with the tenant.
- The provisioning steps themselves (create the SQLite file, migrate,
  create the admin `User`, queue the credentials email) move out of
  `TenantController::store` into `App\Services\TenantProvisioningService::provision(name, host, adminName, adminEmail): Tenant`,
  so both manual creation and self-service signup (§5) call the same
  code instead of duplicating it.

### 5. Self-service signup

Public, unauthenticated route on the super-admin host, linked from the
welcome page: `GET/POST /inscription`. Fields, per your simplification —
no subdomain field: club name, admin name, admin email, plan choice,
payment method + phone.

- `POST /inscription` validates the form, then goes through the same
  `PayPlusGateway::initiate()` checkout as §2 — but since no tenant
  exists yet, the `pending` `Transaction` has `tenant_id = null` and
  carries the club name/admin name/admin email/plan in `metadata`
  (`custom_data` sent to PayPlus carries the `Transaction.reference`
  only, not the whole payload).
- On confirmed payment (webhook or poll, same idempotent path as §2,
  distinguished by `custom_data.tenant_id` being absent): the host is
  auto-slugged from the club name against a new `tenancy.base_domain`
  config value (`TENANT_BASE_DOMAIN` env, same pattern as
  `SUPER_ADMIN_HOST`), de-duplicated against existing `tenants.host` if
  needed. `TenantProvisioningService::provision(...)` runs, then a
  `source = paid` `Subscription` is created from the transaction's plan,
  then the credentials email goes out — identical end state to a
  super-admin-created tenant.
- **Known limitation, inherited from the existing multi-tenant design**:
  there is no wildcard DNS/TLS yet, so the auto-generated subdomain won't
  actually resolve until the super-admin manually does the DNS/TLS/Apache
  steps for it (same manual process as today). Self-service signup
  provisions the tenant + subscription instantly; reachability still
  needs that manual step. Not addressed here — fixing it means wildcard
  DNS, already called out as out of scope in the multi-tenant spec.

### 6. Testing strategy

- `Http::fake()` for all PayPlus HTTP calls (`launchPaiement`'s
  underlying Guzzle client and the raw status-check `Http::get()`) in
  every payment-related feature test — no real PayPlus account needed to
  run the suite.
- Unit tests for `Tenant::accessState()` at the active/grace/blocked
  boundaries, including the per-tenant override vs. platform default.
- Feature tests for `CheckTenantSubscription`: blocked tenant on public
  check-in shows the unavailable page; blocked tenant on `admin.*`
  redirects to the subscription page but can still reach it; grace shows
  the banner but doesn't block; an authenticated super-admin (direct or
  impersonating) is never blocked.
- Feature tests for the checkout → poll/webhook → subscription-activated
  path, including the idempotency case (poll and webhook both fire for
  the same `reference`, only one `Subscription` is created).
- Feature test for self-service signup end-to-end (fake PayPlus success)
  provisioning a tenant + admin `User` + `Subscription` from a
  `tenant_id`-less transaction, including host slug de-duplication.
- Feature test for manual tenant creation now requiring `plan_id` and
  producing a `source = offered` subscription.
- Feature tests for `PlanController` CRUD and the bulk grace-period
  update endpoint (single and multi-tenant selection).

## Out of scope

- Promo codes, refunds, cashback (present in `linkfolio`, not requested
  here).
- Wildcard DNS/TLS for self-service signup reachability (§5) — inherited
  limitation from the multi-tenant spec.
- Invoicing/receipts beyond the transaction/subscription history table.
- Manually cancelling/suspending an active subscription before its
  natural expiry (no `status` column on `subscriptions` — access is
  purely date-driven).
- Card payments via PayPlus — only the mobile-money "straight" flow
  already proven in `linkfolio` is reused.
- Changing `docker-compose.yml` or the DNS/TLS/Apache onboarding runbook.

## Files added/changed

- `database/migrations/central/*` (new: `plans`, `transactions`,
  `subscriptions`, `platform_settings` + seed; add `grace_period_days` to
  `tenants`)
- `app/Models/Plan.php`, `app/Models/Transaction.php`,
  `app/Models/Subscription.php`, `app/Models/PlatformSetting.php` (new,
  all `central` connection)
- `app/Models/Tenant.php` (add `accessState()`, `grace_period_days`)
- `app/Services/PayPlusGateway.php` (new)
- `app/Services/TenantProvisioningService.php` (new — extracted from
  `TenantController::store`)
- `app/Http/Middleware/CheckTenantSubscription.php` (new)
- `app/Http/Controllers/Admin/SubscriptionController.php` (new)
- `app/Http/Controllers/PayPlusCallbackController.php` (new)
- `app/Http/Controllers/SignupController.php` (new — self-service
  `/inscription`)
- `app/Http/Controllers/SuperAdmin/PlanController.php`,
  `app/Http/Controllers/SuperAdmin/PlatformSettingController.php` (new)
- `app/Http/Controllers/SuperAdmin/TenantController.php` (require
  `plan_id`, use `TenantProvisioningService`, bulk grace-period update)
- `app/Http/Requests/SuperAdmin/StoreTenantRequest.php` (add `plan_id`)
- `config/payplus.php` (new, mirrors `linkfolio`'s)
- `config/tenancy.php` (add `base_domain`)
- `routes/web.php` (subscription routes, `/payplus/callback`,
  `/inscription`, plan + platform-setting + bulk grace-period
  super-admin routes)
- `resources/views/admin/subscription/*`,
  `resources/views/super-admin/plans/*`,
  `resources/views/signup/*` (new)
- `composer.json` (add `payplus-africa/payplus` — **new dependency,
  needs approval per project convention**)
