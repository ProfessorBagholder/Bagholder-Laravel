# Bagholder Laravel

A **separate** Livewire 4 + Flux experiment that ports Bagholder dashboard behavior. It is not a replacement for Bagholder and does not share that repository.

## Stack

- Laravel **^13.0**, PHP **8.3+**
- Livewire **^4.0**
- Flux free in the default install; **Flux Pro** is a separate step (you enter your license)

## Run on your Mac

```bash
git clone https://github.com/ProfessorBagholder/Bagholder-Laravel.git
cd Bagholder-Laravel
cp .env.example .env
touch database/database.sqlite
composer install
php artisan key:generate
npm install && npm run build
php artisan migrate
```

### Install Flux Pro (you enter the license)

```bash
composer config http-basic.composer.fluxui.dev YOUR_EMAIL YOUR_LICENSE_KEY
composer require livewire/flux-pro:^2.18
```

Or: `php artisan flux:activate` and follow the prompts. Never commit `auth.json`.

### Serve

```bash
BAGHOLDER_DRY_ORDERS=1 php artisan serve --host=127.0.0.1 --port=43123
```

In another terminal (brackets / order ticks while Orders is closed):

```bash
php artisan schedule:work
```

Open http://127.0.0.1:43123

Keep `BAGHOLDER_DRY_ORDERS=1` unless you explicitly want live order sends.

## Demo journal

`php artisan db:seed` loads a CAD demo book without a Wealthsimple session.

## Connect Wealthsimple

Menu → **Connect session** → paste the same JSON Bagholder uses → **Save and sync**.

## Tests

```bash
php artisan test
```

## Isolation

This is `ProfessorBagholder/Bagholder-Laravel` — separate from `ProfessorBagholder/Bagholder`. Never commit `.env`, `auth.json`, session JSON, or SQLite DBs.
