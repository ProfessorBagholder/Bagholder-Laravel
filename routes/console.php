<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ws:sync', function () {
    set_time_limit(0);
    @ini_set('max_execution_time', '0');
    \App\Wealthsimple\SyncService::runRemaining();
})->purpose('Pull Wealthsimple in a background process; the Livewire poll only reads status');

Artisan::command('market:import {--path= : Desktop bagholder.db path} {--no-bars : Skip price_bars}', function () {
    $path = $this->option('path') ?: null;
    $this->info('Importing market data from desktop sqlite…');
    $result = \App\Journal\MarketImport::import($path, ! $this->option('no-bars'));
    if (! ($result['ok'] ?? false)) {
        $this->error($result['error'] ?? 'Import failed');
        return 1;
    }
    $this->table(
        ['Metric', 'Count'],
        [
            ['quotes', (string) $result['quotes']],
            ['distributions', (string) $result['distributions']],
            ['price_bars', (string) $result['price_bars']],
            ['benchmark_prices', (string) $result['benchmark_prices']],
            ['spy days', (string) $result['spy']],
            ['trade_notes', (string) $result['trade_notes']],
            ['orders', (string) ($result['orders'] ?? 0)],
            ['brackets', (string) ($result['brackets'] ?? 0)],
        ],
    );
    $this->info('Imported from '.$result['path']);
    return 0;
})->purpose('Import quotes/distributions/price_bars/benchmarks from ~/.bagholder/bagholder.db');


use Illuminate\Support\Facades\Schedule;

/*
 | Orders / brackets background ticks (desktop orders_loop + bracket_loop).
 | - Orders open: Livewire poll ~15s runs kick + BracketEngine::tick.
 | - Always: artisan schedule runs orders:tick every 30 seconds when `php artisan schedule:work` (or cron) is up.
 | - BAGHOLDER_DRY_ORDERS=1: refresh no-ops; bracket exits never hit the book.
 */
Schedule::command('orders:tick')->everyThirtySeconds()->withoutOverlapping();
