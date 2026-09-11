<?php

use App\Journal\CsvImport;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('parses canonical Wealthsimple activities-export', function () {
    $text = file_get_contents(base_path('tests/fixtures/activities-export-sample.csv'));
    $parsed = CsvImport::parse($text, 'activities-export-sample.csv');

    expect($parsed['error'])->toBeNull()
        ->and($parsed['format'])->toBe('canonical')
        ->and($parsed['activities'])->not->toBeEmpty()
        ->and($parsed['activities'][0]['source'])->toBe('csv');
});

it('parses legacy Date/Action/Symbol CSV', function () {
    $text = file_get_contents(base_path('tests/fixtures/activities-legacy-sample.csv'));
    $parsed = CsvImport::parse($text, 'legacy.csv');

    expect($parsed['error'])->toBeNull()
        ->and($parsed['format'])->toBe('legacy')
        ->and($parsed['activities'])->toHaveCount(4)
        ->and($parsed['activities'][0]['symbol'])->toBe('VFV')
        ->and($parsed['activities'][0]['activitySubType'])->toBe('BUY');
});

it('rejects unrecognized CSV headers', function () {
    $parsed = CsvImport::parse("foo,bar\n1,2\n", 'nope.csv');

    expect($parsed['format'])->toBe('unknown')
        ->and($parsed['error'])->toContain('Unrecognized CSV format')
        ->and($parsed['activities'])->toBeEmpty();
});

it('merges CSV rows and skips duplicates on reimport', function () {
    $text = file_get_contents(base_path('tests/fixtures/activities-legacy-sample.csv'));
    $first = CsvImport::importText('legacy.csv', $text);
    expect($first['ok'])->toBeTrue()
        ->and($first['added'])->toBe(4)
        ->and(Activity::query()->count())->toBe(4);

    $second = CsvImport::importText('legacy.csv', $text);
    expect($second['ok'])->toBeTrue()
        ->and($second['added'])->toBe(0)
        ->and($second['duplicates'])->toBe(4)
        ->and(Activity::query()->count())->toBe(4);
});

it('appends a manual trade and no-ops a duplicate', function () {
    $first = CsvImport::appendManual([
        'date' => '2026-09-11',
        'symbol' => 'SHOP',
        'side' => 'BUY',
        'qty' => '10',
        'price' => '100',
        'currency' => 'CAD',
        'accountId' => 'manual',
    ]);
    expect($first['ok'])->toBeTrue()->and($first['added'])->toBe(1);

    $dup = CsvImport::appendManual([
        'date' => '2026-09-11',
        'symbol' => 'SHOP',
        'side' => 'BUY',
        'qty' => '10',
        'price' => '100',
        'currency' => 'CAD',
        'accountId' => 'manual',
    ]);
    expect($dup['ok'])->toBeTrue()->and($dup['added'])->toBe(0)->and($dup['duplicates'])->toBe(1);

    $bad = CsvImport::appendManual(['symbol' => '', 'qty' => '0', 'price' => '1']);
    expect($bad['ok'])->toBeFalse()->and($bad['error'])->toContain('required');
});

it('exports closed-trade CSV with desktop headers', function () {
    $csv = CsvImport::exportTradesCsv([
        [
            'entryDate' => '2026-01-02',
            'exitDate' => '2026-02-03',
            'displaySymbol' => 'SHOP',
            'name' => 'Shopify Inc.',
            'accountType' => 'TFSA',
            'kind' => 'stock',
            'displaySide' => 'SELL',
            'quantity' => 10,
            'entryPrice' => 80,
            'exitPrice' => 90,
            'currency' => 'CAD',
            'pnl' => 100,
            'pnlCad' => 100,
            'commission' => 0,
            'holdDays' => 32,
            'grade' => 'A',
            'tag' => 'swing',
            'thesis' => 'Breakout',
        ],
    ]);
    $header = explode("\n", $csv)[0];
    expect($header)->toBe('Open,Close,Symbol,Name,Account,Kind,Side,Status,Qty,Entry,Exit,Currency,P&L,P&L CAD,Fees,Hold days,Grade,Tags,Thesis')
        ->and($csv)->toContain('SHOP')
        ->and($csv)->toContain('Breakout');
});

it('scans a folder of CSVs', function () {
    $dir = base_path('tests/fixtures');
    $set = CsvImport::setWatchFolder($dir);
    expect($set['ok'])->toBeTrue();

    $scan = CsvImport::scanWatchFolder(true);
    expect($scan['ok'])->toBeTrue()
        ->and($scan['added'])->toBeGreaterThan(0)
        ->and(Activity::query()->count())->toBeGreaterThan(0);

    CsvImport::clearWatchFolder();
    expect(CsvImport::watchStatus()['watching'])->toBeFalse();
});

it('clears journal data', function () {
    CsvImport::appendManual([
        'date' => '2026-09-11',
        'symbol' => 'AAPL',
        'side' => 'BUY',
        'qty' => '1',
        'price' => '10',
    ]);
    expect(Activity::query()->count())->toBe(1);

    $out = CsvImport::clearData();
    expect($out['ok'])->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});
