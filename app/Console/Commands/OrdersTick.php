<?php

namespace App\Console\Commands;

use App\Wealthsimple\BracketEngine;
use App\Wealthsimple\OrderService;
use Illuminate\Console\Command;

/**
 * Desktop orders_loop + bracket_loop stand-in.
 *
 * How it ticks:
 * - While Orders is open, Livewire wire:poll.15s calls kickOrdersRefresh + BracketEngine::tick.
 * - This artisan (and the schedule below) mirrors the desktop background loops when the UI is closed.
 * - Under BAGHOLDER_DRY_ORDERS=1 refresh is a no-op and bracket exits are never sent.
 *
 * Schedule (routes/console.php): every 30s orders:tick; every 5s is too chatty for cron —
 * brackets also tick here so armed legs advance without the Orders sheet open.
 */
class OrdersTick extends Command
{
    protected $signature = 'orders:tick {--brackets-only : Skip extended-order refresh} {--orders-only : Skip bracket engine}';

    protected $description = 'Refresh live orders feed (when ORDERS_LIVE) and tick bracket engine';

    public function handle(): int
    {
        $out = [];
        if (! $this->option('brackets-only')) {
            $r = OrderService::kickOrdersRefresh(force: true);
            $out['orders'] = $r;
            $this->line('orders: '.json_encode([
                'ok' => $r['ok'] ?? null,
                'skipped' => $r['skipped'] ?? null,
                'read' => $r['read'] ?? null,
                'added' => $r['added'] ?? null,
                'dry' => ! OrderService::ordersLive(),
            ]));
        }
        if (! $this->option('orders-only')) {
            $r = BracketEngine::tick();
            $out['brackets'] = $r;
            $this->line('brackets: '.json_encode($r + ['dry' => ! OrderService::ordersLive()]));
        }

        return self::SUCCESS;
    }
}
