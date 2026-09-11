# NOTES-flux.md

Facts from [Flux installation](https://fluxui.dev/docs/installation) (2026-09-03). Do not guess beyond that page and the component docs it links.

## What we ran (free, no key)

```bash
composer require livewire/flux
```

Installed: `livewire/flux` **v2.18.0**. Livewire **v4.4.3**. Tailwind in lockfile is **4.3.3** (Flux v2 requires Tailwind **4.2+**).

Layout (official snippet). v2 dropped `@fluxStyles`.

```blade
<head>
    @fluxAppearance
</head>
<body>
    @livewireScripts
    @fluxScripts
</body>
```

`resources/css/app.css` (official snippet), plus Inter via Bunny as the docs recommend:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';
@custom-variant dark (&:where(.dark, .dark *));
```

## Docs gap: Livewire 4

The Flux install page lists **Livewire 3.7.0+** and never mentions Livewire 4. This app still requires Livewire 4. `composer require livewire/flux` succeeded against Livewire 4.4.3 — no composer error to record.

## Pro stopped here

**Flux license needed for composer auth (no key in this environment).**

No `auth.json` in the project. No `composer.fluxui.dev` HTTP basic in this environment. Per the task: do **not** run `php artisan flux:activate` and do **not** run `composer require livewire/flux-pro`.

If a key is supplied later, official placement is Composer HTTP basic on `composer.fluxui.dev` writing **project-root `auth.json`** (already in `.gitignore`). That is not a Laravel `.env` runtime key. Official command:

```bash
composer config http-basic.composer.fluxui.dev your-email your-license-key
```

Never put a license key in git, chat, README, or this file.

## Pro components we wanted (and did not install)

From [flux:select](https://fluxui.dev/components/select) these variants are marked Pro:

- `flux:select variant="listbox"` — custom picker for Account, Exchange, Symbol, Closed in, Price
- `flux:select variant="listbox" searchable` — long symbol lists
- `flux:select variant="combobox"` — symbol typeahead

Also wanted, not installed (Pro or missing from this free package’s Blade stubs):

- `flux:date-picker` — From / To
- `flux:input` — price numbers and search (docs show a free-looking input; this `livewire/flux` tree has no `flux/input` stub, only `otp/input`)
- Native `flux:select` (default variant is documented without a Pro badge; no `flux/select` stub in this package)
- `flux:sheet` — filter sheet (we used `flux:modal flyout`)
- `flux:chart` — equity / monthly (we used PHP SVG)

Until Pro can authenticate, keep **free Flux + Livewire 4**. The dashboard stays runnable.

## Free Flux used on the page

| Component | Where |
| --- | --- |
| `flux:header`, `flux:heading`, `flux:subheading`, `flux:spacer`, `flux:main` | Chrome |
| `flux:button`, `flux:button.group` | Sync, tabs, modal actions |
| `flux:dropdown`, `flux:menu`, `flux:menu.item` | Account / Exchange / Symbol / Closed in / Price / header menu (stand-in for Pro listbox/combobox) |
| `flux:modal`, `flux:modal flyout`, `flux:modal.trigger`, `flux:modal.close` | Connect dialog; filter sheet; executions side panel |
| `flux:table` + `flux:table.sortable` | Closed trades, Activity, Open lots, Executions, annual, by symbol |
| `flux:textarea` | Session JSON |
| `flux:label`, `flux:card`, `flux:badge`, `flux:callout`, `flux:icon`, `flux:separator` | Fields, tiles, sides, empty/error |

## Last-resort native controls (no app `<script>` listeners)

Official Pro docs have the pickers; this environment has no key.

1. `<input type="date">` — From / To (`flux:date-picker` is Pro).
2. `<input type="search">` — symbol filter (`flux:select` combobox / searchable is Pro).
3. `<input type="number">` — Price min/max (`flux:input` stub not in this package).
4. PHP SVG for equity / monthly (`<title>` tooltips). No Flux free chart.

## Livewire 4 APIs (from the 4.x upgrade guide)

Facts from [Upgrade Guide](https://livewire.laravel.com/docs/4.x/upgrading) and 4.x pages/forms/islands docs. Do not use Livewire 3 habits.

- Pin `livewire/livewire:^4.0`. Published `config/livewire.php` uses `component_layout` → `layouts::app` and `component_placeholder` → `components.placeholder` (not `layout` / `lazy_placeholder`).
- Default make command is **sfc** with ⚡ files under `resources/views/components/`. The dashboard page is `pages::dashboard` (`resources/views/pages/⚡dashboard.blade.php`) with `#[Layout('layouts::app')]` and `#[Title('Bagholder Laravel')]`.
- `Route::livewire('/', 'pages::dashboard')` — required for SFC/MFC pages.
- Livewire tags self-close (`<livewire:filter-sheet … />`). `connect-session` / `filter-sheet` / `executions-panel` also accept nested `$slot` and `{{ $attributes }}`.
- `wire:model` is `.self` by default. Filter dates/prices bind `wire:model.live="$parent.form.from"` (input, not a container). Symbol search uses `#[Modelable]` on `search-field`.
- Prefer `data-loading` / `data-loading:` Tailwind over `wire:loading`.
- Islands: `@island(name: 'kpis', always: true)`, `@island(name: 'charts', defer: true, always: true)`, `@island(name: 'blotter', always: true)`, `wire:island="blotter"`, `$wire.$island('kpis').$refresh()`.
- Forms: `Livewire\Form` + `#[Validate]` + `wire:model="form.sessionJson"` / `form.from`.
- Nested children: `#[Reactive]` on filter/executions props; `#[Modelable]` on `search-field`; `#[Defer]` / `defer` on connect; `#[Lazy]` / `lazy` on executions.
- `#[Async]` on `tickSync`; `wire:click.async="startSync"`.
- Navigate: `wire:navigate` on the header home link; persist scroll is `wire:navigate:scroll` on filter menus (not `wire:scroll`).
- JS: `Livewire.interceptMessage` in `resources/js/app.js` and `$intercept` in the dashboard SFC `<script>` (no `@script` — that is for class-based). Interceptors replace commit/request hooks. That is Livewire 4, not a last-resort widget.
- Tests: `Livewire::test('pages::dashboard')` and `Livewire::test('connect-session')`.
- Connect session also uses Laravel 13 `StoreWealthsimpleSessionRequest` (`validated()` / `safe()`). The HTML form includes `@csrf`; `PreventRequestForgery` is in the `web` group via `bootstrap/app.php`.

Flux’s own Alpine inside `flux:modal` is Flux, not app JS.

There are no app `fetch()` / Alpine picker widgets. Last-resort native controls are listed above because Flux Pro pickers are blocked without `composer.fluxui.dev` credentials.
