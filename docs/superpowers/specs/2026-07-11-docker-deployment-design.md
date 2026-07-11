# Docker deployment setup — spec

Date: 2026-07-11

## Context

The user wants to deploy this app to a VPS, but the VPS's system PHP is 8.2
while the app requires PHP `^8.3` (`composer.json`) and local dev runs 8.4.
The VPS already runs Apache as a reverse proxy for other sites/apps —
Apache itself is not part of this repo and won't be touched by it. No
Docker files exist anywhere in the repo today.

Key facts gathered during investigation (see prior conversation / Explore
agent report):

- Database: SQLite only, `DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`,
  `CACHE_STORE=database` — everything lives in one file,
  `database/database.sqlite`, alongside `database/migrations`,
  `database/factories`, `database/seeders` in the same top-level directory.
- Queue: `QUEUE_CONNECTION=database`. `App\Mail\AttendanceThankYouMail`
  implements `ShouldQueue` — a persistent `queue:work` process is required
  in production or thank-you emails never send. `queue:listen` (used in the
  local `composer run dev` script) is dev-only and unsuitable for
  production.
- No scheduled tasks (no `app/Console/Kernel.php`, no `Schedule::` calls in
  `bootstrap/app.php` or `routes/console.php`) — no scheduler process is
  needed.
- No existing Docker/Sail artifacts, no Octane, no PaaS-specific config.
  `php artisan serve` is dev-only (used only in the `composer run dev`
  script) and must not be used in production.
- Frontend assets are built via `npm run build` (Vite) into `public/build`,
  consumed by Blade via `@vite(...)`.

## Goal

Add a Docker-based deployment path (production only — local dev keeps using
`composer run dev` on the existing PHP 8.4 environment) that:

1. Runs the app on a PHP version the app actually supports, independent of
   the VPS's system PHP.
2. Runs a persistent queue worker so queued mail actually gets sent.
3. Persists the SQLite database and `storage/` across container
   recreations/redeploys.
4. Exposes a plain HTTP port on the host that the VPS's existing Apache can
   reverse-proxy to (Apache keeps handling TLS/domain routing — out of
   scope for this repo).

## Design

### 1. `Dockerfile` (multi-stage)

- **Stage `frontend`** (`node:22-alpine`): copies `package.json` /
  `package-lock.json`, `npm ci`, copies the rest of the source, runs
  `npm run build` → produces `public/build/**`.
- **Stage `vendor`** (`composer:2`): copies `composer.json` /
  `composer.lock`, runs `composer install --no-dev --optimize-autoloader
  --no-interaction --no-scripts` (no app code needed yet, so `--no-scripts`
  avoids running artisan commands against a not-yet-complete filesystem).
- **Stage `runtime`** (`php:8.4-fpm-alpine`):
  - Installs PHP extensions: `pdo_sqlite`, `sqlite3`, `mbstring`, `dom`,
    `gd`, `zip`, `bcmath`, `intl`, `pcntl`, `opcache`, `xml`, `fileinfo`.
  - Installs `nginx` and `supervisor` (both available as Alpine packages).
  - Copies application source, `vendor/` from the `vendor` stage, and
    `public/build` from the `frontend` stage.
  - Copies an nginx server block (`docker/nginx.conf`) and a supervisord
    config (`docker/supervisord.conf`) that runs `php-fpm` and `nginx`
    together as the container's foreground process.
  - Sets ownership/permissions on `storage/` and `bootstrap/cache/` for the
    `www-data` user.
  - Copies `docker/entrypoint.sh` as the container `ENTRYPOINT`; `CMD`
    starts supervisord.
  - `EXPOSE 80`.

### 2. `docker/entrypoint.sh`

Runs once per container start, before handing off to the main process
(`exec "$@"` at the end so signals still reach supervisord/php-fpm
correctly):

```sh
#!/bin/sh
set -e

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
```

This same entrypoint is used by both the `app` and `queue` services (see
below) — running `migrate --force` from both is safe (Laravel's migrator is
idempotent / no-ops when there's nothing new to migrate), and it removes
any ordering dependency between the two containers on startup.

### 3. `docker-compose.yml`

Two services, one shared image (built once, referenced via `build:` on the
`app` service and `image:`-less `build:` on `queue` too, or built with the
same `build:` block — Compose will reuse the cache):

```yaml
services:
  app:
    build: .
    restart: unless-stopped
    env_file: .env
    ports:
      - "${APP_PORT:-8080}:80"
    volumes:
      - sqlite-data:/var/www/html/database/database.sqlite
      - storage-data:/var/www/html/storage

  queue:
    build: .
    restart: unless-stopped
    env_file: .env
    command: php artisan queue:work --tries=3 --max-time=3600
    volumes:
      - sqlite-data:/var/www/html/database/database.sqlite
      - storage-data:/var/www/html/storage

volumes:
  sqlite-data:
  storage-data:
```

Notes:
- `sqlite-data` is mounted **at the sqlite file path itself**, not at
  `database/` — mounting the whole `database/` directory would shadow the
  image's `migrations/`, `factories/`, and `seeders/` subdirectories with an
  initially-empty volume, breaking `migrate`.
- `${APP_PORT:-8080}` reads `APP_PORT` from `.env` (or the shell
  environment) with `8080` as a fallback — the user will pick their actual
  free port by setting `APP_PORT` in their VPS `.env` file, no file edits
  needed.
- `env_file: .env` — the same `.env` conventions as any Laravel app
  (`APP_KEY`, `APP_URL`, `MAIL_*`, `ADMIN_EMAIL`/`ADMIN_PASSWORD` for the
  seeder, etc.), created once on the VPS from `.env.example` and never
  committed. `APP_KEY` must be generated once (`docker compose run --rm app
  php artisan key:generate --show`, paste the result into `.env`) and then
  left stable — regenerating it on every start would invalidate existing
  encrypted sessions/cookies.

### 4. `docker/nginx.conf`

Standard Laravel public/ nginx server block: `root /var/www/html/public`,
`index index.php`, `try_files $uri $uri/ /index.php?$query_string`,
`location ~ \.php$` proxies to php-fpm over TCP
(`fastcgi_pass 127.0.0.1:9000;` — the official `php:8.4-fpm-alpine` image's
pool already listens there by default, so no custom php-fpm pool config
file is needed), denies access to dotfiles (`location ~ /\.`). Listens on
port 80.

### 5. `docker/supervisord.conf`

Two `[program:]` blocks, `php-fpm` (foreground mode,
`php-fpm --nodaemonize`) and `nginx` (foreground mode, `nginx -g "daemon
off;"`), both `autostart=true`, `autorestart=true`. Supervisord itself runs
in the foreground as the container's `CMD`.

### 6. `.dockerignore`

Excludes `.git`, `node_modules`, `vendor`, `.env`, `.env.*` (except
`.env.example`), `database/database.sqlite`, `storage/logs/*`,
`storage/framework/{cache,sessions,views}/*` contents, `public/build`
(rebuilt in the `frontend` stage, not copied from the build context),
`.claude`, `docs/superpowers`, `tests`.

### 7. Reverse proxy on the VPS (not part of this repo)

Documented for the user in chat only (their Apache config lives outside
this repository): once the `app` service is exposed on `127.0.0.1:<APP_PORT>`,
an Apache vhost proxies to it with `ProxyPass` / `ProxyPassReverse`
(`mod_proxy` + `mod_proxy_http`), keeping Apache as the TLS/domain
termination point exactly as it is today for the VPS's other sites.

## Out of scope

- Any change to Apache configuration on the VPS (documented in chat, not
  committed).
- A local-dev Docker variant (explicitly declined by the user — local dev
  keeps using `composer run dev` on PHP 8.4).
- CI/CD (image publishing, auto-deploy on push) — this spec only covers
  building and running the image on the VPS.
- MySQL/Postgres support — the app is and stays SQLite-only.
- TLS/certificate management — owned entirely by the VPS's existing Apache.

## Files added

- `Dockerfile`
- `docker-compose.yml`
- `docker/nginx.conf`
- `docker/supervisord.conf`
- `docker/entrypoint.sh`
- `.dockerignore`
