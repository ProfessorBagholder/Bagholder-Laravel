<?php

use App\Journal\Fifo;
use App\Journal\Grouping;
use App\Journal\Listings;
use App\Journal\Metrics;
use App\Journal\Sides;
use App\Journal\Symbols;
use App\Support\Money;

it('formats every dollar with a $ and unicode minus', function () {
    expect(Money::formatCad(1234.56))->toBe('$1,234.56');
    expect(Money::formatCad(-1234.56))->toBe('−$1,234.56');
    expect(Money::formatCad(0))->toBe('$0.00');
    expect(Money::formatCad(-0.4))->toBe('−$0.40');
});

it('strips Canadian exchange suffixes for display', function () {
    expect(Symbols::listingTicker('SHOP.TO'))->toBe('SHOP');
    expect(Symbols::listingTicker('WEED.V'))->toBe('WEED');
    expect(Symbols::listingTicker('ABC.CN'))->toBe('ABC');
    expect(Symbols::listingTicker('XYZ.NE'))->toBe('XYZ');
    expect(Symbols::listingTicker('AAPL'))->toBe('AAPL');
});

it('labels short closes as COVER not Trade', function () {
    expect(Sides::displaySide(['openDirection' => 'SHORT', 'side' => 'BUY']))->toBe('COVER');
    expect(Sides::displaySide(['openDirection' => 'LONG', 'side' => 'SELL']))->toBe('SELL');
    expect(Sides::displaySide(['openDirection' => 'LONG', 'side' => 'BUY']))->toBe('BUY');
});

it('labels BUYTOCLOSE activity Side as COVER', function () {
    expect(Sides::activityDisplaySide(['activityType' => 'Trade', 'activitySubType' => 'BUYTOCLOSE']))->toBe('COVER');
    expect(Sides::activityDisplaySide(['activityType' => 'Trade', 'activitySubType' => 'SELLTOOPEN']))->toBe('SELL');
    expect(Sides::activityDisplaySide(['activityType' => 'Trade', 'activitySubType' => 'BUY']))->toBe('BUY');
    expect(Sides::activityDisplaySide(['activityType' => 'Trade', 'activitySubType' => 'SELL']))->toBe('SELL');
});

it('does not feed until-side-change groups into computeMetrics', function () {
    $slices = [
        tradeSlice('SHOP', '2025-03-01', 15, -150, 'LONG'),
        tradeSlice('SHOP', '2025-04-01', 15, 150, 'LONG'),
        tradeSlice('CNQ', '2024-08-01', 50, -250, 'LONG'),
        tradeSlice('WEED', '2024-07-10', 100, 400, 'SHORT'),
    ];

    $untilSide = Grouping::defaultGroupsUntilSideChange($slices);
    $perClose = Grouping::groupClosedByClose($slices);

    expect($untilSide)->toHaveCount(3);
    expect($perClose)->toHaveCount(4);

    $metricsFromGroups = Metrics::compute($untilSide);
    $metricsFromCloses = Metrics::compute($perClose);

    expect($metricsFromCloses['tradeCount'])->toBe(4);
    expect($metricsFromGroups['tradeCount'])->toBe(3);
    expect($metricsFromCloses['winCount'])->toBe(2);
    expect($metricsFromCloses['lossCount'])->toBe(2);
    expect($metricsFromCloses['evenCount'])->toBe(0);
    expect(Metrics::winRateSubtitle($metricsFromCloses))->toBe('2 W, 2 L, 0 BE');
    expect(Metrics::profitFactorSubtitle($metricsFromCloses))->toBe('$550.00 W, $400.00 L');
    expect($metricsFromCloses['profitFactor'])->toEqual(550 / 400);
    expect($metricsFromCloses['expectancy'])->toEqual((2 / 4) * 275 + (2 / 4) * (-200));
});

it('matches FIFO shorts so COVER closes exist', function () {
    $acts = [
        fill('s1', '2024-05-01', 'WEED.TO', 'SELLTOOPEN', -100, 12),
        fill('s2', '2024-07-10', 'WEED.TO', 'BUYTOCLOSE', 100, 8),
    ];
    $fifo = Fifo::match($acts);
    expect($fifo['closed'])->toHaveCount(1);
    expect($fifo['closed'][0]['openDirection'])->toBe('SHORT');
    expect(Sides::displaySide($fifo['closed'][0]))->toBe('COVER');
    expect($fifo['closed'][0]['pnl'])->toEqual(400.0);
});

it('builds listing lines as company · exchange', function () {
    Listings::boot([
        ['id' => 'sec-shop', 'symbol' => 'SHOP.TO', 'name' => 'Shopify Inc.', 'primaryExchange' => 'TSX', 'primaryMic' => 'XTSX'],
    ]);
    $line = Listings::listingLine(['symbol' => 'SHOP.TO', 'securityId' => 'sec-shop']);
    expect($line)->toBe('Shopify Inc. · TSX: SHOP');
});

function tradeSlice(string $sym, string $exit, float $qty, float $pnl, string $dir): array
{
    return [
        'id' => $sym.'|'.$exit,
        'accountId' => 'tfsa-1',
        'accountType' => 'TFSA',
        'symbol' => $sym,
        'name' => $sym,
        'currency' => 'CAD',
        'side' => $dir === 'SHORT' ? 'BUY' : 'SELL',
        'quantity' => $qty,
        'entryPrice' => 10,
        'exitPrice' => 10 + ($pnl / $qty),
        'entryDate' => '2024-01-01',
        'exitDate' => $exit,
        'holdDays' => 10,
        'commission' => 0,
        'pnl' => $pnl,
        'pnlCad' => $pnl,
        'openDirection' => $dir,
        'buyActivityId' => $sym.'-b-'.$exit,
        'sellActivityId' => $sym.'-s-'.$exit,
    ];
}

function fill(string $id, string $day, string $sym, string $sub, float $qty, float $px): array
{
    return [
        'id' => $id,
        'occurredAt' => $day.'T14:00:00Z',
        'transactionDate' => $day,
        'accountId' => 'tfsa-1',
        'fifoId' => 'tfsa-1',
        'accountType' => 'TFSA',
        'activityType' => 'Trade',
        'activitySubType' => $sub,
        'symbol' => $sym,
        'name' => $sym,
        'currency' => 'CAD',
        'quantity' => $qty,
        'unitPrice' => $px,
        'commission' => 0,
        'netCashAmount' => -1 * $qty * $px,
        'category' => 'trade',
    ];
}
