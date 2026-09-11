<?php

use App\Journal\Fifo;
use App\Journal\Fx;
use App\Journal\Grouping;
use App\Journal\Metrics;
use App\Models\Meta;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bagholderBookActivities(): array
{
    $paths = array_filter([
        getenv('BAGHOLDER_BOOK_JSON') ?: null,
        '/tmp/bh-book.json',
        storage_path('app/testing/bh-book.json'),
    ]);
    foreach ($paths as $path) {
        if (is_file($path)) {
            $book = json_decode((string) file_get_contents($path), true);
            if (is_array($book) && ! empty($book['activities'])) {
                return $book['activities'];
            }
        }
    }
    try {
        $raw = @file_get_contents('http://127.0.0.1:8765/api/book');
        if ($raw) {
            $book = json_decode($raw, true);
            if (is_array($book) && ! empty($book['activities'])) {
                return $book['activities'];
            }
        }
    } catch (Throwable) {
    }
    $db = '/Users/md/.bagholder/bagholder.db';
    if (! is_file($db)) {
        return [];
    }
    $pdo = new PDO('sqlite:'.$db);
    $rows = $pdo->query('select * from activities')->fetchAll(PDO::FETCH_ASSOC);
    $acts = [];
    foreach ($rows as $r) {
        $acts[] = [
            'id' => $r['id'],
            'canonicalId' => $r['canonical_id'],
            'occurredAt' => $r['occurred_at'],
            'transactionDate' => $r['transaction_date'],
            'accountId' => $r['account_id'],
            'bookId' => $r['book_id'],
            'fifoId' => $r['fifo_id'],
            'accountType' => $r['account_type'],
            'activityType' => $r['activity_type'],
            'activitySubType' => $r['activity_sub_type'],
            'symbol' => $r['symbol'],
            'currency' => $r['currency'],
            'quantity' => $r['quantity'] !== null ? (float) $r['quantity'] : null,
            'unitPrice' => $r['unit_price'] !== null ? (float) $r['unit_price'] : null,
            'commission' => $r['commission'] !== null ? (float) $r['commission'] : null,
            'netCashAmount' => $r['net_cash_amount'] !== null ? (float) $r['net_cash_amount'] : null,
            'category' => $r['category'],
        ];
    }

    return $acts;
}

it('matches ledger.html win rate when fill ids sort with localeCompare (strcasecmp)', function () {
    $acts = bagholderBookActivities();
    if ($acts === []) {
        $this->markTestSkipped('Bagholder book / bagholder.db not available');
    }

    // Live app FX (same as Home). RefreshDatabase wipes meta — restore from the served sqlite if needed.
    $fxPath = database_path('database.sqlite');
    if (is_file($fxPath)) {
        $live = new PDO('sqlite:'.$fxPath);
        $row = $live->query("select value from meta where key='fx_by_date'")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['value']) {
            Meta::putValue('fx_by_date', $row['value']);
        }
    }
    Fx::boot(Meta::json('fx_by_date', []));

    $fifo = Fifo::match($acts);
    $closed = Fx::apply($fifo['closed']);
    $grouped = Grouping::groupClosedByClose($closed);
    $m = Metrics::compute($grouped, 0, 0.0, 0.0);

    expect(count($grouped))->toBe(405)
        ->and($m['winCount'])->toBe(300)
        ->and($m['lossCount'])->toBe(104)
        ->and($m['evenCount'])->toBe(1)
        ->and($m['realizedPnlCad'])->toEqualWithDelta(145559.64, 0.05)
        ->and($m['grossProfit'])->toEqualWithDelta(190889.44, 0.05)
        ->and($m['grossLoss'])->toEqualWithDelta(45329.81, 0.05);
});

it('orders fill ids case-insensitively like JS localeCompare', function () {
    $a = 'order-00WtUDTr7ECc';
    $b = 'order-00WtUDfSATCK';
    // strcmp puts T before f (ASCII); localeCompare/strcasecmp puts f before T when case-folded
    expect(strcasecmp($b, $a))->toBeLessThan(0)
        ->and(strcmp($b, $a))->toBeGreaterThan(0);
});
