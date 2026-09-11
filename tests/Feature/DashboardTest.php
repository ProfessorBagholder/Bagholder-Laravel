<?php

use App\Support\Money;
use Database\Seeders\DemoJournalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoJournalSeeder::class);
});

it('renders the dashboard with tiles and closed trades', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Realized P&amp;L', false)
        ->assertSee('Profit factor')
        ->assertSee('Closed trades');
});

it('shows COVER on short closes and company · exchange listings', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('COVER')
        ->assertSee('SHOP')
        ->assertSee('Shopify Inc. · TSX: SHOP');
});

it('shows Open lots tab with demo long lot', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('Open lots')
        ->set('tab', 'open')
        ->assertSee('LONG')
        ->assertSee('Opened');
});

it('formats dollars with a leading $', function () {
    $html = Livewire::test('pages::dashboard')->html();
    expect($html)->toContain('$');
    expect($html)->not->toContain('CAD ');
    expect($html)->not->toContain('formatMoney');
    expect(Money::formatCad(-800))->toBe('−$800.00');
});

it('opens the executions side panel for a closed trade', function () {
    $component = Livewire::test('pages::dashboard');
    $book = $component->instance()->book;
    $id = $book['closed'][0]['id'] ?? null;
    expect($id)->not->toBeNull();

    $component->call('openExecutions', $id)
        ->assertSet('executionsOpen', true)
        ->assertSet('selectedTradeId', $id);
});

it('filters closed trades by symbol', function () {
    Livewire::test('pages::dashboard')
        ->call('setFilter', 'symbol', 'CNQ.TO')
        ->assertSet('form.symbol', 'CNQ.TO');
});
