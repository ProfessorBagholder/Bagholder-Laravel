<?php

use App\Wealthsimple\ActivityMapper;

it('pools CAD and USD TFSA sides by nickname to the min id', function () {
    $pools = ActivityMapper::fifoPoolIds([
        ['id' => 'tfsa-n1zbnbyx', 'nickname' => '🚀 Trading'],
        ['id' => 'tfsa-VCXFo2ZFqA', 'nickname' => '🚀 Trading'],
        ['id' => 'lira-Wd1VvDaU_w', 'nickname' => '🤷 Retirement'],
        ['id' => 'lira-OGfzG9yHwA', 'nickname' => '🤷 Retirement'],
        ['id' => 'rrsp-alone', 'nickname' => 'Solo'],
    ]);

    expect($pools['tfsa-n1zbnbyx'])->toBe('tfsa-VCXFo2ZFqA')
        ->and($pools['tfsa-VCXFo2ZFqA'])->toBe('tfsa-VCXFo2ZFqA')
        ->and($pools['lira-Wd1VvDaU_w'])->toBe('lira-OGfzG9yHwA')
        ->and($pools['lira-OGfzG9yHwA'])->toBe('lira-OGfzG9yHwA')
        ->and($pools['rrsp-alone'])->toBe('rrsp-alone');
});

it('unions linkedAccount pairs', function () {
    $pools = ActivityMapper::fifoPoolIds([
        ['id' => 'tfsa-zzz', 'nickname' => '', 'linkedAccount' => ['id' => 'tfsa-aaa']],
        ['id' => 'tfsa-aaa', 'nickname' => ''],
    ]);

    expect($pools['tfsa-zzz'])->toBe('tfsa-aaa')
        ->and($pools['tfsa-aaa'])->toBe('tfsa-aaa');
});

it('sets fifoId on map from pool roots', function () {
    $accounts = [
        'tfsa-n1zbnbyx' => ['id' => 'tfsa-n1zbnbyx', 'nickname' => '🚀 Trading'],
        'tfsa-VCXFo2ZFqA' => ['id' => 'tfsa-VCXFo2ZFqA', 'nickname' => '🚀 Trading'],
    ];
    $row = ActivityMapper::map([
        'occurredAt' => '2026-06-10T14:32:07.055000+00:00',
        'canonicalId' => 'order-pool-test',
        'accountId' => 'tfsa-n1zbnbyx',
        'type' => 'DIY_SELL',
        'subType' => 'SELL',
        'assetSymbol' => 'CH',
        'assetQuantity' => 100000,
        'amount' => 14500,
        'status' => 'POSTED',
    ], $accounts);

    expect($row['fifoId'])->toBe('tfsa-VCXFo2ZFqA')
        ->and($row['accountId'])->toBe('tfsa-n1zbnbyx');
});
