# Bagholder Laravel

A **separate** Livewire 4 + Flux experiment that ports Bagholder dashboard behavior (closed trades, filters, KPI tiles, executions side panel, Wealthsimple session). It is not a replacement for Bagholder and does not share that repository.

## Stack

- Laravel **^13.0**, PHP **8.3+**. Bootstrap is `bootstrap/app.php` (`Application::configure` / `withRouting` / `withMiddleware`). Providers live in `bootstrap/providers.php`. There is no `Http/Kernel.php`. CSRF is `PreventRequestForgery`. Frontend is Vite (`@vite` / `laravel-vite-plugin`), not Mix. `composer run dev` runs HTTP + queue + Vite.
- Livewire **^4.0** (not v3). Page route is `Route::livewire('/', 'pages::dashboard')`. Config keys are `component_layout` and `component_placeholder`. See `NOTES-flux.md` for the 4.x APIs this app uses.
- Flux UI free (`livewire/flux`) only. Do **not** add `livewire/flux-pro` or `composer.fluxui.dev` credentials to this public repo. See `NOTES-flux.md`.

## Run on your Mac (recommended for local testing)

Requires PHP 8.3+, Composer, and Node 20+.

```bash
git clone https://github.com/ProfessorBagholder/Bagholder-Laravel.git
cd Bagholder-Laravel
cp .env.example .env
touch database/database.sqlite
composer install
php artisan key:generate
npm install && npm run build
php artisan migrate
# optional demo book without Wealthsimple:
# php artisan db:seed
BAGHOLDER_DRY_ORDERS=1 php artisan serve --host=127.0.0.1 --port=43123
```

In another terminal (brackets / order ticks while Orders is closed):

```bash
php artisan schedule:work
```

Open [http://127.0.0.1:43123](http://127.0.0.1:43123). Keep `BAGHOLDER_DRY_ORDERS=1` unless you explicitly want live order sends.

### Point at your existing Bagholder data (optional)

- Journal: this app uses its own Laravel SQLite schema (`database/database.sqlite` by default). Prefer Connect + Sync, or `php artisan db:seed` for a demo book. Do not drop desktop `~/.bagholder/bagholder.db` on top unless you know the schemas match.
- Wealthsimple session: Menu → **Connect session** and paste the same JSON Bagholder uses (`refresh_token`, optional `client_id`, `wssdi`, `session_id`, `user_agent`). The app stores it encrypted in `ws_sessions` — never commit session JSON or `.env`.

## Docker (optional)

```bash
docker compose up --build
```

Open [http://127.0.0.1:8080](http://127.0.0.1:8080).

No Breeze/Jetstream and no `php artisan install:api` — 13.x auth starter kits are Fortify-based (React/Svelte/Vue/Livewire); this app has no login surface. Session paste uses Livewire `Form` plus a Laravel Form Request (`validated()` / `safe()`).

## Demo journal

`php artisan db:seed` loads a CAD demo book (Shopify/CNQ/Canopy/Apple, a COVER short, open lot, daily NAV, S&P seed). Use it without a Wealthsimple session.

## Connect Wealthsimple

1. Capture a session JSON the same way Bagholder does (`refresh_token`, optional `client_id`, `wssdi`, `session_id`, `user_agent`).
2. Menu → **Connect session** → paste JSON → **Save and sync**.
3. Sync walks accounts, activity, NAV, listings, and FRED SP500. The header replaces “Synced … ago” with the current step while that runs (`wire:poll` + `#[Async] tickSync` on `pages::dashboard`).

This app scrapes the production `clientId` from Wealthsimple’s login JS using the same method as Bagholder. It does not invent a second client id.

## Tests

Pest is the test runner (also documented as PHPUnit). Feature tests use `Livewire::test('pages::dashboard')`.

```bash
php artisan test
```

## Isolation

This is `ProfessorBagholder/Bagholder-Laravel` — separate from `ProfessorBagholder/Bagholder`. Never commit `.env`, `auth.json`, session JSON, SQLite DBs, or Flux Pro / `composer.fluxui.dev` credentials.

## Orders / brackets background loops

Desktop Bagholder runs `orders_loop` (~30s) and `bracket_loop` (~5s) while connected. Laravel mirrors that two ways:

1. **Orders sheet open** — `wire:poll.15s` on the Orders panel calls `OrderService::kickOrdersRefresh()` then `BracketEngine::tick()`.
2. **Scheduled artisan** — `php artisan orders:tick` (also `Schedule::command('orders:tick')->everyThirtySeconds()` in `routes/console.php`). Run `php artisan schedule:work` on the box (or system cron for `schedule:run`) so brackets keep ticking when Orders is closed.

`BAGHOLDER_DRY_ORDERS=1` (default): extended-order refresh is a no-op; `_place_exit` stubs log once and never send. Live paths are implemented behind `OrderService::ordersLive()`.
