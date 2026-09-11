<?php

use App\Journal\Orders;
use App\Wealthsimple\OrderService;
use App\Wealthsimple\OrderStore;

beforeEach(function () {
    putenv('BAGHOLDER_DRY_ORDERS=1');
    $_ENV['BAGHOLDER_DRY_ORDERS'] = '1';
    $_SERVER['BAGHOLDER_DRY_ORDERS'] = '1';
});

it('reports orders dry when BAGHOLDER_DRY_ORDERS=1', function () {
    expect(OrderService::ordersLive())->toBeFalse();
});

it('placeOrder dry returns ok status dry and notice without needing live session', function () {
    $accounts = OrderStore::orderAccounts();
    if ($accounts === []) {
        $this->markTestSkipped('No tradable accounts in bagholder.db');
    }
    $sec = OrderStore::resolveSecurity('QNC');
    if (! $sec) {
        $this->markTestSkipped('No QNC security in bagholder.db');
    }
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 0.01,
        'symbol' => 'QNC',
        'accountId' => $accounts[0]['id'],
    ]);
    expect($r['ok'])->toBeTrue()
        ->and($r['status'])->toBe('dry')
        ->and($r['notice'])->toContain('Not sent (orders are off)');
    $row = OrderStore::getOrder($r['id']);
    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('dry');
});

it('cancelOrder dry does not mutate pending and returns dry notice shape', function () {
    $pending = null;
    foreach (Orders::payload()['orders'] ?? [] as $o) {
        if (in_array($o['status'] ?? '', ['sent', 'pending'], true) && ($o['type'] ?? '') !== 'STOP') {
            $pending = $o;
            break;
        }
    }
    if (! $pending) {
        $this->markTestSkipped('No pending order to dry-cancel');
    }
    $before = OrderStore::getOrder($pending['id']);
    $r = OrderService::cancelOrder($pending['id']);
    expect($r['dry'] ?? false)->toBeTrue()
        ->and($r['notice'])->toContain('Cancel not sent')
        ->and($r['error'])->toContain('BAGHOLDER_DRY_ORDERS');
    $after = OrderStore::getOrder($pending['id']);
    expect($after['status'])->toBe($before['status']);
});

function pestTicketAccounts(): array
{
    $accounts = OrderStore::orderAccounts();
    if ($accounts === []) {
        test()->markTestSkipped('No tradable accounts in bagholder.db');
    }
    $sec = OrderStore::resolveSecurity('QNC');
    if (! $sec) {
        test()->markTestSkipped('No QNC security in bagholder.db');
    }

    return [$accounts[0], $sec];
}

it('orderRequest BUY stores stopLoss/takeProfit shape and omits them from WS create input', function () {
    [$acct, $sec] = pestTicketAccounts();
    [$row, $req, $err] = OrderService::orderRequest([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 2,
        'limitPrice' => 1.71,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 1.62, 'trail' => 5, 'trailUnit' => 'pct'],
        'takeProfit' => ['price' => 1.88],
    ]);
    expect($err)->toBe('')
        ->and($req)->not->toHaveKey('stopLoss')
        ->and($req)->not->toHaveKey('takeProfit')
        ->and($row['stopLoss'])->toMatchArray([
            'kind' => 'stop',
            'price' => 1.62,
            'trail' => 5.0,
            'trailUnit' => 'pct',
        ])
        ->and($row['takeProfit'])->toMatchArray(['price' => 1.88]);
});

it('orderRequest BUY trail stopLoss requires trail and keeps trailUnit', function () {
    [$acct] = pestTicketAccounts();
    [$row, $req, $err] = OrderService::orderRequest([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.71,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'trail', 'trail' => 5, 'trailUnit' => 'pct', 'price' => 1.62],
    ]);
    expect($err)->toBe('')
        ->and($row['stopLoss']['kind'])->toBe('trail')
        ->and($row['stopLoss']['trail'])->toBe(5.0)
        ->and($row['stopLoss']['trailUnit'])->toBe('pct');

    [, , $missing] = OrderService::orderRequest([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.71,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'trail', 'trailUnit' => 'pct'],
    ]);
    expect($missing)->toBe('A trail is required.');
});

it('orderRequest SELL strips stopLoss/takeProfit even when sent', function () {
    [$acct] = pestTicketAccounts();
    [$row, $req, $err] = OrderService::orderRequest([
        'side' => 'SELL',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.71,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'nope', 'price' => 9],
        'takeProfit' => ['price' => 99],
    ]);
    expect($err)->toBe('')
        ->and($row['stopLoss'])->toBeNull()
        ->and($row['takeProfit'])->toBeNull()
        ->and($req)->not->toHaveKey('stopLoss');
});

it('placeOrder dry BUY with SL/TP inserts waiting bracket', function () {
    [$acct] = pestTicketAccounts();
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 0.01,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 0.009, 'trail' => null, 'trailUnit' => 'pct'],
        'takeProfit' => ['price' => 0.02],
    ]);
    expect($r['ok'])->toBeTrue()
        ->and($r['status'])->toBe('dry')
        ->and($r['bracketId'] ?? '')->toStartWith('bracket-');
    $row = OrderStore::getOrder($r['id']);
    expect($row['stopLoss']['kind'])->toBe('stop')
        ->and($row['takeProfit']['price'])->toBe(0.02);
    $b = OrderStore::getBracket($r['bracketId']);
    expect($b)->not->toBeNull()
        ->and($b['status'])->toBe('waiting')
        ->and($b['orderId'])->toBe($r['id'])
        ->and($b['slKind'])->toBe('stop')
        ->and($b['tif'])->toBe(OrderService::BRACKET_TIF)
        ->and($b['tpPrice'])->toBe(0.02);
});

it('placeOrder dry SELL does not create a bracket', function () {
    [$acct] = pestTicketAccounts();
    $r = OrderService::placeOrder([
        'side' => 'SELL',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 0.01,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 0.009],
        'takeProfit' => ['price' => 0.02],
    ]);
    expect($r['ok'])->toBeTrue()
        ->and($r)->not->toHaveKey('bracketId');
    $row = OrderStore::getOrder($r['id']);
    expect($row['stopLoss'])->toBeNull()
        ->and($row['takeProfit'])->toBeNull();
});

it('placeOrder dry SELL ends an armed bracket covering the sold qty', function () {
    [$acct] = pestTicketAccounts();
    $sid = 'sec-pest-sltp-'.uniqid();
    $bid = 'bracket-pest-'.uniqid();
    OrderStore::insertBracket([
        'id' => $bid,
        'orderId' => 'order-pest-parent-'.uniqid(),
        'accountId' => $acct['id'],
        'securityId' => $sid,
        'symbol' => 'PESTSLTP',
        'currency' => 'CAD',
        'quantity' => 8,
        'tif' => OrderService::BRACKET_TIF,
        'slKind' => 'stop',
        'slPrice' => 1.0,
        'tpPrice' => 2.0,
        'status' => 'armed',
    ]);
    $r = OrderService::placeOrder([
        'side' => 'SELL',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 8,
        'limitPrice' => 1.5,
        'symbol' => 'PESTSLTP',
        'securityId' => $sid,
        'accountId' => $acct['id'],
    ]);
    expect($r['ok'])->toBeTrue()->and($r['status'])->toBe('dry');
    $b = OrderStore::getBracket($bid);
    expect($b['status'])->toBe('done')
        ->and($b['outcome'])->toBe('sold from the ticket')
        ->and($b['slOrderId'])->toBe('')
        ->and($b['tpOrderId'])->toBe('');
});

it('placeOrder dry SELL of part of an armed bracket re-arms the remainder', function () {
    [$acct] = pestTicketAccounts();
    $sid = 'sec-pest-sltp-'.uniqid();
    $bid = 'bracket-pest-'.uniqid();
    OrderStore::insertBracket([
        'id' => $bid,
        'orderId' => 'order-pest-parent-'.uniqid(),
        'accountId' => $acct['id'],
        'securityId' => $sid,
        'symbol' => 'PESTSLTP',
        'currency' => 'CAD',
        'quantity' => 10,
        'tif' => OrderService::BRACKET_TIF,
        'slKind' => 'stop',
        'slPrice' => 1.0,
        'status' => 'armed',
    ]);
    $r = OrderService::placeOrder([
        'side' => 'SELL',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 4,
        'limitPrice' => 1.5,
        'symbol' => 'PESTSLTP',
        'securityId' => $sid,
        'accountId' => $acct['id'],
    ]);
    expect($r['ok'])->toBeTrue();
    $b = OrderStore::getBracket($bid);
    expect($b['status'])->toBe('armed')
        ->and($b['quantity'])->toBe(6.0)
        ->and($b['slOrderId'])->toBe('')
        ->and($b['attempts'])->toBe(0);
    OrderStore::updateBracket($bid, ['status' => 'done', 'outcome' => 'pest cleanup']);
});

it('quote returns desktop shape with bid/ask/mid/name/orderTypes/BP fields', function () {
    $accounts = OrderStore::orderAccounts();
    if ($accounts === []) {
        $this->markTestSkipped('No tradable accounts');
    }
    $r = OrderService::quote('QNC', '', $accounts[0]['id'], '');
    expect($r)->toHaveKeys(['ok', 'quote', 'orderTypes', 'accounts', 'buyingPower', 'cash', 'marginAvailable', 'fxUsdCad', 'live', 'source']);
    if (! empty($r['ok'])) {
        expect($r['quote'])->toHaveKeys(['symbol', 'name', 'last', 'bid', 'ask', 'mid', 'bidSize', 'askSize', 'currency', 'multiplier'])
            ->and($r['quote']['symbol'])->toBe('QNC')
            ->and($r['orderTypes'])->toBeArray()
            ->and($r['orderTypes'])->not->toBeEmpty();
    } else {
        expect($r['error'] ?? '')->not->toBe('');
    }
});

it('api order quote route returns 200 json shape', function () {
    $resp = $this->getJson('/api/order/quote?symbol=QNC');
    $resp->assertOk();
    $json = $resp->json();
    expect($json)->toHaveKey('ok')
        ->and($json)->toHaveKey('orderTypes')
        ->and($json)->toHaveKey('quote');
});

it('dry place with SL/TP notice appends stop and target', function () {
    [$acct, $sec] = pestTicketAccounts();
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.71,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 1.62, 'trail' => 5, 'trailUnit' => 'pct'],
        'takeProfit' => ['price' => 1.88],
    ]);
    expect($r['ok'])->toBeTrue()
        ->and($r['status'])->toBe('dry')
        ->and($r['notice'])->toContain('stop')
        ->and($r['notice'])->toContain('target')
        ->and($r['bracketId'] ?? '')->not->toBe('');
});

it('Review then place: goReview does not place; submit on review places dry', function () {
    [$acct] = pestTicketAccounts();
    // Simulate Review→Submit via OrderService only (UI step tested by component contract).
    $before = count(OrderStore::listOrders());
    // First Review would only validate — place happens on Submit:
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 2,
        'limitPrice' => 1.70,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 1.60],
        'takeProfit' => ['price' => 1.90],
    ]);
    expect($r['ok'])->toBeTrue()->and($r['status'])->toBe('dry');
    $after = OrderStore::listOrders();
    expect(count($after))->toBeGreaterThanOrEqual($before + 1);
    $bracket = OrderStore::getBracket($r['bracketId'] ?? '');
    expect($bracket)->not->toBeNull()
        ->and($bracket['status'])->toBe('waiting');
    // Dry arm step leaves waiting
    \App\Wealthsimple\BracketEngine::tick([]);
    $bracket2 = OrderStore::getBracket($r['bracketId']);
    expect($bracket2['status'])->toBe('waiting');
});

it('cancelBracket dry ends waiting bracket locally', function () {
    [$acct] = pestTicketAccounts();
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.50,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 1.40],
        'takeProfit' => ['price' => 1.80],
    ]);
    expect($r['bracketId'] ?? '')->not->toBe('');
    $c = OrderService::cancelBracket($r['bracketId']);
    expect($c['ok'])->toBeTrue();
    $b = OrderStore::getBracket($r['bracketId']);
    expect($b['status'])->toBeIn(['done', 'closing']);
});

it('refreshOrders under dry skips Wealthsimple and returns skipped dry', function () {
    $r = OrderService::refreshOrders();
    expect($r['ok'])->toBeTrue()
        ->and($r['skipped'] ?? '')->toBe('dry')
        ->and($r['read'])->toBe(0)
        ->and($r['added'])->toBe(0);
});

it('kickOrdersRefresh under dry is a no-op skip', function () {
    $r = OrderService::kickOrdersRefresh(force: true);
    expect($r['ok'])->toBeTrue()
        ->and($r['skipped'] ?? '')->toBe('dry');
});

it('appStatus maps Wealthsimple statuses like desktop', function () {
    expect(OrderService::appStatus('SUBMITTED'))->toBe('pending')
        ->and(OrderService::appStatus('FILLED'))->toBe('filled')
        ->and(OrderService::appStatus('CANCEL_PENDING'))->toBe('cancelling')
        ->and(OrderService::appStatus('EXPIRED'))->toBe('expired');
});

it('parseExtendedOrder maps soOrdersExtendedOrder fields', function () {
    $parsed = OrderService::parseExtendedOrder([
        'soOrdersExtendedOrder' => [
            'status' => 'SUBMITTED',
            'filledQuantity' => 0,
            'averageFilledPrice' => null,
            'submittedAtUtc' => '2026-01-01T00:00:00Z',
            'expiredAtUtc' => '',
            'submittedQuantity' => 10,
            'limitPrice' => 1.5,
            'stopPrice' => null,
            'timeInForce' => 'UNTIL_CANCEL',
            'securityCurrency' => 'cad',
            'canonicalAccountId' => 'acct-1',
            'securityId' => 'sec-1',
            'orderType' => 'limit',
        ],
    ]);
    expect($parsed)->not->toBeNull()
        ->and($parsed['status'])->toBe('pending')
        ->and($parsed['wsStatus'])->toBe('SUBMITTED')
        ->and($parsed['quantity'])->toBe(10.0)
        ->and($parsed['tif'])->toBe('UNTIL_CANCEL')
        ->and($parsed['type'])->toBe('LIMIT');
});

it('placeExit under dry logs stub and does not insert a live exit', function () {
    [$acct] = pestTicketAccounts();
    $before = count(OrderStore::listOrders());
    $b = [
        'id' => 'bracket-pest-exit-'.uniqid(),
        'orderId' => 'order-pest-parent-'.uniqid(),
        'accountId' => $acct['id'],
        'securityId' => $acct['id'].'-sec', // resolve may fail — use QNC
        'symbol' => 'QNC',
        'currency' => 'CAD',
        'quantity' => 1,
        'slPrice' => 1.0,
        'status' => 'armed',
    ];
    $sec = OrderStore::resolveSecurity('QNC');
    if ($sec) {
        $b['securityId'] = $sec['id'];
    }
    [$oid, $err] = \App\Wealthsimple\BracketEngine::placeExit($b, 'STOP', 1.0, 'stop');
    expect($err)->toBe('')
        ->and($oid)->toBe('');
    expect(count(OrderStore::listOrders()))->toBe($before);
});

it('armStep under dry leaves waiting bracket waiting for dry entry', function () {
    [$acct] = pestTicketAccounts();
    $r = OrderService::placeOrder([
        'side' => 'BUY',
        'type' => 'LIMIT',
        'tif' => 'DAY',
        'quantity' => 1,
        'limitPrice' => 1.10,
        'symbol' => 'QNC',
        'accountId' => $acct['id'],
        'stopLoss' => ['kind' => 'stop', 'price' => 1.00],
        'takeProfit' => ['price' => 1.30],
    ]);
    expect($r['bracketId'] ?? '')->not->toBe('');
    \App\Wealthsimple\BracketEngine::tick([]);
    $b = OrderStore::getBracket($r['bracketId']);
    expect($b['status'])->toBe('waiting');
});

it('trail watch under dry updates local high-water without placing', function () {
    [$acct] = pestTicketAccounts();
    $sid = 'sec-pest-trail-'.uniqid();
    $bid = 'bracket-pest-trail-'.uniqid();
    OrderStore::insertBracket([
        'id' => $bid,
        'orderId' => 'order-pest-trail-'.uniqid(),
        'accountId' => $acct['id'],
        'securityId' => $sid,
        'symbol' => 'TRAILX',
        'currency' => 'CAD',
        'quantity' => 5,
        'tif' => OrderService::BRACKET_TIF,
        'slKind' => 'trail',
        'slTrail' => 5,
        'slTrailUnit' => 'pct',
        'slPrice' => 9.5,
        'highWater' => 10.0,
        'status' => 'armed',
        'armedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    \App\Wealthsimple\BracketEngine::watchStep(
        OrderStore::getBracket($bid),
        ['last' => 11.0, 'bid' => 10.9, 'marketStatus' => 'OPEN'],
    );
    $b = OrderStore::getBracket($bid);
    expect((float) $b['highWater'])->toBeGreaterThanOrEqual(11.0)
        ->and((float) $b['slPrice'])->toBeGreaterThan(9.5)
        ->and($b['slOrderId'] ?? '')->toBe('');
    OrderStore::updateBracket($bid, ['status' => 'done', 'outcome' => 'pest cleanup']);
});

it('adjustBracket dry removes stop leg locally', function () {
    [$acct] = pestTicketAccounts();
    $bid = 'bracket-pest-adj-'.uniqid();
    OrderStore::insertBracket([
        'id' => $bid,
        'orderId' => 'order-pest-adj-'.uniqid(),
        'accountId' => $acct['id'],
        'securityId' => 'sec-pest-adj',
        'symbol' => 'ADJX',
        'currency' => 'CAD',
        'quantity' => 2,
        'tif' => OrderService::BRACKET_TIF,
        'slKind' => 'stop',
        'slPrice' => 1.0,
        'tpPrice' => 2.0,
        'status' => 'armed',
    ]);
    $r = \App\Wealthsimple\BracketEngine::adjustBracket($bid, 'sl', null, null, true);
    expect($r['ok'])->toBeTrue()->and($r['dry'] ?? false)->toBeTrue();
    $b = OrderStore::getBracket($bid);
    expect($b['slKind'])->toBe('')
        ->and($b['tpPrice'])->toBe(2.0);
    OrderStore::updateBracket($bid, ['status' => 'done', 'outcome' => 'pest cleanup']);
});

it('orders:tick artisan succeeds under dry', function () {
    $this->artisan('orders:tick')->assertSuccessful();
});

it('quote meta path can surface cash from balances when present', function () {
    $accounts = OrderStore::orderAccounts();
    if ($accounts === []) {
        $this->markTestSkipped('No accounts');
    }
    $r = OrderService::quote('QNC', '', $accounts[0]['id'], '');
    expect($r)->toHaveKeys(['buyingPower', 'cash', 'marginAvailable']);
    // cash may still be null if bagholder.db has no cash balance row — just ensure key exists and gaps are honest
    if (($r['source'] ?? '') === 'meta' && $r['cash'] === null) {
        expect(implode(' ', $r['gaps'] ?? []))->toContain('Cash');
    }
});
