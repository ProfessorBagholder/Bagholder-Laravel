<?php

use Database\Seeders\DemoJournalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoJournalSeeder::class);
});

it('renders filter listboxes and a closed date range', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('Closed in')
        ->assertSee('All years')
        ->assertSee('Symbol')
        ->assertSee('Account')
        ->assertSee('Exchange')
        ->assertSee('Price')
        ->assertSee('Between')
        ->assertSee('Under')
        ->assertSee('Equal')
        ->assertSee('Over')
        ->assertSee('Hold')
        ->assertSee('P&L')
        ->assertSee('Qty')
        ->assertSee('More than')
        ->assertSee('Less than');
});

it('narrows closed trades and tiles when symbol, account, exchange, price, or closed-in change', function () {
    $page = Livewire::test('pages::dashboard');
    $all = $page->instance()->book;
    $allCount = count($all['closed']);
    $allPnl = $all['metrics']['realizedPnlCad'] ?? null;

    expect($allCount)->toBeGreaterThan(1);

    $page->set('form.symbol', 'SHOP.TO');
    $shop = $page->instance()->book;
    expect(count($shop['closed']))->toBeLessThan($allCount)
        ->and($shop['metrics']['realizedPnlCad'])->not->toEqual($allPnl);
    expect($page->instance()->form->active())->toBeTrue();

    $page->call('resetFilters');
    $page->set('form.account', 'RRSP');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);

    $page->call('resetFilters');
    $page->set('form.exchange', 'TSX');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);

    $page->call('resetFilters');
    $page->set('form.closedIn', '2024');
    expect($page->get('form.from'))->toBe('2024-01-01')
        ->and($page->get('form.to'))->toBe('2024-12-31')
        ->and($page->instance()->form->active())->toBeTrue();
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);

    $page->call('resetFilters');
    $page->set('form.priceOp', 'under');
    $page->set('form.priceMin', '50');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);
    expect($page->html())->toContain('Reset filters');
});

it('shows Demo data when the journal is loaded but Wealthsimple is not connected', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('Demo data')
        ->assertDontSee('Working…')
        ->assertDontSee('Livewire experiment');
});

it('narrows closed trades when Hold, P&L, or Qty range filters change', function () {
    $page = Livewire::test('pages::dashboard');
    $allCount = count($page->instance()->book['closed']);
    expect($allCount)->toBeGreaterThan(1);

    $page->set('form.holdOp', 'under');
    $page->set('form.holdMin', '100');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);
    expect($page->instance()->form->active())->toBeTrue();

    $page->call('resetFilters');
    $page->set('form.pnlOp', 'under');
    $page->set('form.pnlMin', '0');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);

    $page->call('resetFilters');
    $page->set('form.qtyOp', 'over');
    $page->set('form.qtyMin', '40');
    expect(count($page->instance()->book['closed']))->toBeLessThan($allCount);
});
