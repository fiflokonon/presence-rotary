# Multi-tenant club deployment — spec

Date: 2026-07-23

## Context

The app is already deployed for one Rotary club on a subdomain of the
user's domain, backed by a single SQLite file and a single Docker stack
(`app` + `queue` services, see `docs/superpowers/specs/2026-07-11-docker-deployment-design.md`).
A second organization now needs its own deployment on a different
subdomain of the same server. Running a second, nearly-identical Docker
stack side by side was ruled out — the user wants one running instance
that serves multiple clubs, each isolated from the others, rather than N
duplicated stacks.

Key facts about the current app (gathered during investigation):

- Single SQLite database, single `DB_CONNECTION=sqlite`, no notion of a
  tenant anywhere in the code.
- Club-specific configuration (`ClubSetting::current()`) is a singleton
  row — branding for admin layout, public check-in page, PDF export, and
  emails all read from it (see recent commits `951665e`..`813d473`).
- A single flat `User` model / `web` guard, no roles, no custom
  middleware exists yet (`bootstrap/app.php` only sets
  `redirectGuestsTo` and `trustProxies`).
- Admin panel lives at `/admin` under the same host as the public
  check-in page (`routes/web.php`), not on a separate subdomain.
- Two Mailables are queued (`ShouldQueue`) and carry Eloquent models as
  constructor properties: `AttendanceThankYouMail` (`Attendance`,
  `MeetingSession`) and `NewAdminCredentialsMail` (`User`), dispatched via
  `Mail::to(...)->queue(...)` from `MeetingSessionController` and
  `UserController`.
- Tests run with `DB_DATABASE=:memory:`, `RefreshDatabase`, on the single
  `sqlite` connection.
- No wildcard DNS/TLS available yet — every subdomain is added manually
  (DNS `A` record, `certbot --expand`, Apache vhost with `ProxyPass`).
  Some clubs may eventually bring their own external domain instead of a
  subdomain of the user's domain, but that is not needed for the two
  clubs in scope now.

## Goal

Serve multiple clubs (tenants) from a single running Docker stack
(`app` + `queue`), each isolated in its own SQLite database and reachable
on its own subdomain, plus a super-admin panel for the platform owner to
manage tenants, view any club's admin panel, and see aggregated stats
across clubs.

## Design

### 1. Data layer: central + per-tenant connections

- New `central` DB connection (SQLite,
  `database/data/central.sqlite`), with its own migrations
  (living in the normal `database/migrations` directory, each marked
  `protected $connection = 'central';` so `php artisan migrate` splits
  work across connections automatically — no custom multi-database
  migration command needed). Two tables:
  - `tenants`: `id`, `name`, `subdomain`, `sqlite_path`, timestamps.
  - `super_admins`: `id`, `name`, `email`, `password`, timestamps —
    same shape as `User` but a separate table/connection, guarded by its
    own auth guard.
- The existing `sqlite` connection stays the default connection used by
  every existing model (`User`, `ClubSetting`, `Attendance`, etc.), but
  its `database` path is rewritten at runtime, per request, to
  `database/data/tenants/{id}.sqlite` for whichever tenant is current.
- A single service, `TenantContext`, is the only place that knows how to
  switch: `TenantContext::use(Tenant $tenant)` does
  `config(['database.connections.sqlite.database' => $tenant->sqlite_path])`
  + `DB::purge('sqlite')`, and remembers the current tenant
  (`TenantContext::current()`) so `ClubSetting::current()`, per-tenant
  file storage, etc. keep working unmodified. The tenant-resolution
  middleware, the queued mail jobs, and the aggregated dashboard all call
  this same service — none of them re-implement the switch.

### 2. Tenant resolution (club-facing routes)

- A `ResolveTenant` middleware sits in front of the public check-in
  routes and the `admin.*` route group (but not the super-admin routes,
  see §3):
  - Reads `Request::getHost()`, extracts the subdomain, looks up the
    matching `Tenant` via the `central` connection.
  - Found → `TenantContext::use($tenant)`.
  - Not found → 404. No silent fallback to a default tenant — a DNS
    misconfiguration must not leak one club's data onto another host.
- Per-tenant public storage (club logo, etc.) moves from
  `storage/app/public/club/...` to
  `storage/app/public/tenants/{tenant_id}/club/...`. Only
  `ClubSettingController` needs to change (it already builds this path
  via `Storage::disk('public')->store(...)`).
- No other controller or view changes: they keep calling
  `ClubSetting::current()`, `Attendance::query()`, etc. — the default
  connection changed under them, not their code.

### 3. Super-admin panel

- Lives on its own dedicated subdomain, `admin.<domain>`, onboarded with
  the same manual DNS/TLS/Apache recipe as any club subdomain (§5) — kept
  consistent with the existing convention where each subdomain serves its
  own panel under `/admin` (a club's admin lives at
  `clubX.<domain>/admin`; the platform's admin lives at
  `admin.<domain>`, representing no club). This subdomain is excluded
  from `ResolveTenant` — it only ever talks to the `central` connection,
  never switches the `sqlite` connection on its own.
- New `super_admin` guard (Eloquent provider on a `SuperAdmin` model,
  `central` connection), with its own login/logout routes.
- **Provisioning**: a `super-admin/tenants` page (list + creation form).
  On create: generates `database/data/tenants/{id}.sqlite`, creates the
  empty file, switches `TenantContext` onto it and runs
  `Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true])`,
  restores the previous context, and only then inserts the `tenants` row
  — so a failed migration never leaves an orphaned registry entry.
- **Context switch ("view as club X")**: a button per tenant stores
  `session(['impersonating_tenant_id' => $id])` and redirects into a
  route group that reuses the **existing admin controllers/views**
  unchanged, gated by a second middleware, `ImpersonateTenant` (active
  only on this route group), which reads the session instead of the
  `Host` header. A banner injected into the admin layout shows "Vous
  consultez : {club} — Quitter la vue" while impersonating.
- **Aggregated dashboard**: a controller loops over `Tenant::all()`,
  switches `TenantContext` for each, runs the same queries used on a
  club's own dashboard (member count, attendance this month), collects
  the results, restores the starting context at the end. No caching for
  now — revisit if the tenant count grows enough to matter.

### 4. Queued mail: tenant-aware jobs

`AttendanceThankYouMail` and `NewAdminCredentialsMail` are `ShouldQueue`
and carry Eloquent models as constructor properties. `SerializesModels`
refetches those models from the database **at job-payload
deserialization time** — before any application code in `handle()` gets
a chance to switch the tenant connection. Left as-is, a queued mail
would either fail to find its models or, worse, silently pick up another
tenant's row with the same ID.

Fix: stop queuing the Mailables directly. Instead:

- One dedicated job per mail (`SendAttendanceThankYouMailJob`,
  `SendNewAdminCredentialsMailJob`), `ShouldQueue`, whose constructor
  stores only scalars (`tenant_id`, `attendance_id`,
  `meeting_session_id`, `next_session_title`, `next_session_date`) —
  never an Eloquent model, so nothing is refetched at deserialization
  time.
- In `handle()`: `TenantContext::use(Tenant::find($this->tenant_id))`,
  then reload the models fresh on the now-correct connection, then send
  the mail synchronously (`Mail::to(...)->send(...)`) — no need to
  requeue since we're already inside a queue worker.
- `MeetingSessionController` and `UserController` dispatch these jobs
  instead of calling `Mail::to(...)->queue(...)`.

`MailSettingTestMail` is unaffected — it isn't `ShouldQueue` and is sent
synchronously within the request.

### 5. Infra: Docker, migrations, DNS/TLS/Apache

- File layout: `database/data/central.sqlite` +
  `database/data/tenants/{id}.sqlite`. The existing Docker volume
  (`sqlite-data:/var/www/html/database/data`) already covers this layout
  — no change to `docker-compose.yml`.
- `docker/entrypoint.sh`: migrates `central` first
  (`--database=central`), then loops over every existing
  `database/data/tenants/*.sqlite` file and runs
  `migrate --database=sqlite --force` against each — so redeploying a
  new app version migrates every already-provisioned tenant, not just
  new ones.
- DNS/TLS/Apache onboarding (per subdomain, manual, no wildcard yet):
  1. DNS: `A` record `<sub>.<domain> → VPS_IP`.
  2. TLS: `certbot --expand` to add the domain to the existing
     certificate (HTTP-01 challenge, works once DNS has propagated).
  3. Apache: add a vhost/`ServerAlias` for `<sub>.<domain>` with the same
     `ProxyPass`/`ProxyPassReverse` to `127.0.0.1:<APP_PORT>`, reload.
  `admin.<domain>` is onboarded with this exact same recipe, as a fourth
  "tenant-shaped" host that just isn't in the `tenants` table.
- Existing club (currently the only tenant, live in production): no data
  migration. It is recreated from scratch as an ordinary tenant through
  the super-admin provisioning form once the new system is live; the old
  single-tenant deployment is decommissioned after DNS cutover.

### 6. Testing strategy

- The `central` connection is added to the test environment as
  `:memory:` too, migrated by the same `RefreshDatabase` call (migrations
  tagged `$connection = 'central'` are picked up natively — no custom
  test harness needed).
- `TestCase::setUp()` creates a default test tenant and calls
  `TenantContext::use($tenant)` pointing at the already-active
  `:memory:` connection, so every existing feature test keeps passing
  unmodified — they never need to know multi-tenancy exists.
- Gotcha: `:memory:` cannot model real isolation between two tenants
  (switching `config()` to a different `:memory:` path just creates a
  second, independent empty database, not a second inspectable tenant).
  Tests that specifically verify cross-tenant isolation use real
  temporary SQLite files (`storage/framework/testing/tenants/*.sqlite`),
  created and cleaned up by a dedicated test helper.
- New coverage: subdomain resolution (known host → right tenant, unknown
  host → 404), cross-tenant data isolation, super-admin auth,
  tenant provisioning, context switch (banner + correct data shown),
  aggregated dashboard, and both queued mail jobs (mail is sent with the
  triggering tenant's data/branding, never the queue worker's
  previously-active tenant).

## Out of scope

- Wildcard DNS/TLS (revisit once the number of clubs makes manual
  onboarding painful).
- Clubs bringing their own external domain (not a subdomain of the
  user's domain) — same `ResolveTenant` design would support it since it
  keys purely on `Host`, but no onboarding tooling for it is built now.
- Caching/precomputing the aggregated dashboard.
- Any change to `docker-compose.yml` itself.

## Files added/changed

- `app/Services/TenantContext.php` (new)
- `app/Http/Middleware/ResolveTenant.php` (new)
- `app/Http/Middleware/ImpersonateTenant.php` (new)
- `app/Models/Tenant.php`, `app/Models/SuperAdmin.php` (new)
- `app/Jobs/SendAttendanceThankYouMailJob.php`,
  `app/Jobs/SendNewAdminCredentialsMailJob.php` (new)
- `app/Http/Controllers/SuperAdmin/*` (new: auth, tenant management,
  impersonation, aggregated dashboard)
- `database/migrations/central/*` (new)
- `config/database.php` (add `central` connection)
- `config/auth.php` (add `super_admin` guard + provider)
- `bootstrap/app.php` (register `ResolveTenant` / `ImpersonateTenant`)
- `routes/web.php` (super-admin route group)
- `app/Http/Controllers/Admin/ClubSettingController.php` (per-tenant
  storage path)
- `app/Http/Controllers/Admin/MeetingSessionController.php`,
  `app/Http/Controllers/Admin/UserController.php` (dispatch jobs instead
  of queuing Mailables)
- `app/Mail/AttendanceThankYouMail.php`,
  `app/Mail/NewAdminCredentialsMail.php` (drop `ShouldQueue`)
- `docker/entrypoint.sh` (central + per-tenant migration loop)
- `tests/TestCase.php` / `tests/Pest.php` (default test tenant bootstrap)
