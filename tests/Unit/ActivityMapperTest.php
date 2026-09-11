<?php

use App\Wealthsimple\ActivityMapper;

it('skips pending and cancelled fills the same way Bagholder does', function () {
    $base = [
        'occurredAt' => '2026-06-10T13:47:29.116000+00:00',
        'canonicalId' => 'order-fake',
        'type' => 'DIY_SELL',
        'subType' => 'SELL',
        'assetSymbol' => 'CH',
        'assetQuantity' => 420892,
        'amount' => 2841021,
        'status' => 'SUBMITTED',
    ];
    expect(ActivityMapper::skip($base))->toBeTrue()
        ->and(ActivityMapper::map($base))->toBeNull();

    $base['status'] = 'CANCELLED';
    expect(ActivityMapper::skip($base))->toBeTrue();
});

it('skips share lending rows', function () {
    expect(ActivityMapper::skip([
        'occurredAt' => '2026-01-01T00:00:00Z',
        'type' => 'SHARE_LENDING',
        'status' => 'POSTED',
        'canonicalId' => 'x',
    ]))->toBeTrue();
});

it('maps posted DIY_SELL to Trade/SELL, not Trade/DIY_SELL', function () {
    $row = ActivityMapper::map([
        'occurredAt' => '2026-06-10T14:32:07.055000+00:00',
        'canonicalId' => 'order-real',
        'type' => 'DIY_SELL',
        'subType' => 'SELL',
        'assetSymbol' => 'CH',
        'assetQuantity' => 100000,
        'amount' => 14500,
        'status' => 'POSTED',
    ]);
    expect($row)->not->toBeNull()
        ->and($row['activityType'])->toBe('Trade')
        ->and($row['activitySubType'])->toBe('SELL')
        ->and($row['category'])->toBe('trade')
        ->and($row['quantity'])->toBe(-100000.0)
        ->and($row['unitPrice'])->toBe(0.145);
});

it('does not treat GraphQL TRADE + DIY_SELL as a FIFO sell', function () {
    $row = ActivityMapper::map([
        'occurredAt' => '2026-06-10T13:47:29.116000+00:00',
        'canonicalId' => 'order-00YUDwmw0W14',
        'type' => 'TRADE',
        'subType' => 'DIY_SELL',
        'assetSymbol' => 'CH',
        'assetQuantity' => 420892,
        'amount' => 2841021,
        'status' => 'POSTED',
    ]);
    expect($row)->not->toBeNull()
        ->and($row['category'])->not->toBe('trade')
        ->and($row['activityType'])->not->toBe('Trade');
});

it('signs DIY_BUY cash negative even when amountSign is positive', function () {
    $cash = ActivityMapper::signedCash([
        'type' => 'DIY_BUY',
        'amount' => 725,
        'amountSign' => 'positive',
    ]);
    expect($cash)->toBe(-725.0);
});
