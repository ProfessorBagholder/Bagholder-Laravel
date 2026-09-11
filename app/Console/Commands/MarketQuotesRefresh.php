<?php

namespace App\Console\Commands;

use App\Journal\QuoteRefresh;
use App\Journal\Snapshot;
use Illuminate\Console\Command;

class MarketQuotesRefresh extends Command
{
    protected $signature = 'market:quotes {--no-yahoo : Skip Yahoo equity fetch (still imports desktop DB + Cboe options)}';

    protected $description = 'Refresh quotes from desktop bagholder.db, Yahoo equities, and Cboe USD option chains';

    public function handle(): int
    {
        $positions = [];
        try {
            $book = Snapshot::book([], [
                'closed' => ['key' => 'exitDate', 'dir' => 'desc'],
                'open' => ['key' => 'date', 'dir' => 'desc'],
                'activity' => ['key' => 'when', 'dir' => 'desc'],
            ]);
            $positions = $book['positions'] ?? [];
        } catch (\Throwable $e) {
            $this->warn('Book unavailable for held list: '.$e->getMessage());
        }
        $out = QuoteRefresh::run(yahoo: ! $this->option('no-yahoo'), positions: $positions);
        $this->table(['Metric', 'Value'], [
            ['imported', (string) $out['imported']],
            ['fetched', (string) $out['fetched']],
            ['cboe', (string) ($out['cboe'] ?? 0)],
            ['total', (string) $out['total']],
            ['changed', $out['changed'] ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
